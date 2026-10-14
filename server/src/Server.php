<?php
/**
 * The OSCAR server: one process, one select loop, every connection.
 *
 * A client makes two connections. The first is the authorizer (family 0x17): it asks for
 * an MD5 key, signs in, and is told where the BOS server is plus a cookie. The second is
 * the BOS connection, opened with that cookie, which carries everything else. Both land
 * on the same listener here; the cookie in the sign-on frame tells them apart.
 *
 * The SNACs handled are the ones libpurple's OSCAR plugin sends (2.5.1 on stock webOS,
 * 2.14 on webOS CE and Lunacy). Anything else is logged and ignored.
 */

require_once __DIR__ . '/Wire.php';
require_once __DIR__ . '/Store.php';

final class Conn
{
    public string $in = '';
    public string $out = '';
    public int $seq;
    public string $kind = 'new';          // new | auth | bos
    public float $lastHeard;
    public float $opened;
    public bool $closing = false;          // close once $out is flushed

    // Rate state (one class for every SNAC; see Server::rates)
    public float $rateLevel = Server::RATE_INITIAL;
    public float $rateLast = 0;
    public bool $rateLimited = false;

    // Authorizer state
    public ?string $authKey = null;

    // BOS session state
    public string $sn = '';                // display form
    public string $nsn = '';               // normalized
    public int $signon = 0;
    public bool $ready = false;            // client ready (01,02) received
    public int $oserviceVersion = 3;
    public int $idleSince = 0;
    public ?string $away = null;           // away message, null when available
    public string $awayEnc = '';
    public string $profile = '';
    public string $profileEnc = '';
    public string $caps = '';
    /** @var array<string,bool> normalized buddy names */
    public array $buddies = [];

    public function __construct(public $sock, public int $id, public string $peer, public string $ip)
    {
        $this->seq = random_int(0, 0x7fff);
        $this->lastHeard = $this->opened = microtime(true);
    }
}

final class Server
{
    /** @var array<int, Conn> */
    private array $conns = [];
    /** @var array<string, array<int, Conn>> online BOS sessions by normalized screen name, then id */
    private array $online = [];
    /** @var array<string, array{sn:string, until:float}> sign-on cookies by hex */
    private array $cookies = [];
    private array $listeners = [];
    private int $nextId = 1;
    /** @var array<string, int> open connections by peer address */
    private array $perIp = [];
    private int $refused = 0;
    private float $lastRefusedLog = 0;
    private float $lastSelectFail = 0;
    private float $lastSweep = 0;

    private const IDLE_TIMEOUT = 300;      // seconds without a frame, keepalives included
    private const FAMILIES = [0x0001, 0x0002, 0x0003, 0x0004, 0x0009, 0x0013];

    /**
     * The rate class every SNAC is in, as sent in rates(): AOL's own class 1 values, in ms.
     * The level is a moving average of the time between a client's SNACs over a window of
     * 80; libpurple holds its own sends back before the level reaches the alert mark, so only
     * a client that ignores the class is ever limited or dropped.
     */
    public const RATE_WINDOW = 80;
    public const RATE_CLEAR = 2500;
    public const RATE_ALERT = 2000;
    public const RATE_LIMIT = 1500;
    public const RATE_DISCONNECT = 800;
    public const RATE_INITIAL = 3433;
    public const RATE_MAX = 6000;

    /** Bytes a client may leave unread before it is dropped. */
    private const MAX_UNREAD = 1 << 20;
    /** An instant message's text, as the ICBM parameters (04,05) tell clients. */
    private const MAX_MESSAGE = 8000;
    /** Buddy list items per user, and the bytes of one item's TLVs. */
    private const MAX_FEEDBAG_ITEMS = 2200;
    private const MAX_FEEDBAG_TLVS = 4096;

    /**
     * Connection limits. stream_select can't watch a descriptor numbered 1024 or more, and
     * when it fails nothing is served, so max_conns stays well under that.
     */
    private array $limits = [
        'max_conns' => 900,                // every open connection
        'max_per_ip' => 16,                // from one address
        'signon_timeout' => 30,            // seconds a connection has to reach its BOS session
        'max_sessions' => 4,               // BOS sessions per screen name; past it the oldest is signed off
    ];

    public function __construct(
        private array $listen,             // ["0.0.0.0:5190", ...]
        private string $advertise,         // "oscar.webosarchive.org:5190", sent to clients for BOS
        private Accounts $accounts,
        private UserData $data,
        private int $logLevel = self::LOG_NORMAL,   // LOG_NORMAL, LOG_VERBOSE or LOG_DEBUG
        array $limits = [],
    ) {
        foreach ($limits as $k => $v) if (isset($this->limits[$k]) && $v !== null) $this->limits[$k] = (int)$v;
        $this->limits['max_conns'] = max(1, min(1000, $this->limits['max_conns']));
        $this->limits['max_sessions'] = max(1, $this->limits['max_sessions']);
    }

    public function run(): void
    {
        foreach ($this->listen as $addr) {
            $s = @stream_socket_server("tcp://$addr", $errno, $errstr);
            if (!$s) throw new RuntimeException("can't listen on $addr: $errstr");
            stream_set_blocking($s, false);
            $this->listeners[] = $s;
            $this->log("listening on $addr, BOS advertised as {$this->advertise}");
        }

        while (true) {
            $read = $this->listeners;
            $write = [];
            foreach ($this->conns as $c) {
                $read[] = $c->sock;
                if ($c->out !== '') $write[] = $c->sock;
            }
            $except = null;
            if (@stream_select($read, $write, $except, 1) === false) {
                // Never spin: say so once a minute and give the loop a breath.
                if (microtime(true) - $this->lastSelectFail > 60) {
                    $this->log("stream_select failed with " . count($this->conns) . " connections open");
                    $this->lastSelectFail = microtime(true);
                }
                usleep(200000);
                $this->tick();
                continue;
            }

            foreach ($read as $s) {
                if (in_array($s, $this->listeners, true)) { $this->accept($s); continue; }
                $c = $this->bySock($s);
                if ($c) $this->readFrom($c);
            }
            foreach ($write as $s) {
                $c = $this->bySock($s);
                if ($c) $this->flush($c);
            }
            $this->tick();
        }
    }

    // ---- connections ----

    private function accept($listener): void
    {
        $sock = @stream_socket_accept($listener, 0, $peer);
        if (!$sock) return;
        $ip = self::ipOf((string)$peer);
        if (count($this->conns) >= $this->limits['max_conns'] || ($this->perIp[$ip] ?? 0) >= $this->limits['max_per_ip']) {
            // Accepted and closed at once, so the listener doesn't stay readable.
            @fclose($sock);
            $this->refused++;
            return;
        }
        stream_set_blocking($sock, false);
        $c = new Conn($sock, $this->nextId++, (string)$peer, $ip);
        $this->conns[$c->id] = $c;
        $this->perIp[$ip] = ($this->perIp[$ip] ?? 0) + 1;
        $this->log("#{$c->id} connected from {$c->peer}");
        // Both sides send FLAP version 1 on channel 1 as soon as the connection is up.
        $this->send($c, Wire::CH_SIGNON, pack('N', 1));
    }

    private function readFrom(Conn $c): void
    {
        $chunk = @fread($c->sock, 65536);
        if ($chunk === '' || $chunk === false) {
            if (feof($c->sock)) { $this->drop($c, 'closed by peer'); }
            return;
        }
        $c->in .= $chunk;
        $c->lastHeard = microtime(true);

        while (strlen($c->in) >= 6) {
            if ($c->in[0] !== '*') { $this->drop($c, 'not a FLAP stream'); return; }
            $len = unpack('n', $c->in, 4)[1];
            if (strlen($c->in) < 6 + $len) break;
            $channel = ord($c->in[1]);
            $payload = substr($c->in, 6, $len);
            $c->in = substr($c->in, 6 + $len);
            try {
                $this->frame($c, $channel, $payload);
            } catch (UnderflowException $e) {
                $this->log("#{$c->id} malformed frame on channel $channel: {$e->getMessage()}");
            }
            if (!isset($this->conns[$c->id])) return;
        }
    }

    private function flush(Conn $c): void
    {
        $n = @fwrite($c->sock, $c->out);
        if ($n === false) { $this->drop($c, 'write failed'); return; }
        $c->out = substr($c->out, $n);
        if ($c->out === '' && $c->closing) $this->drop($c, 'done');
    }

    private function drop(Conn $c, string $why): void
    {
        if (!isset($this->conns[$c->id])) return;
        unset($this->conns[$c->id]);
        if (--$this->perIp[$c->ip] <= 0) unset($this->perIp[$c->ip]);
        @fclose($c->sock);
        $this->log("#{$c->id} {$c->sn} dropped: $why");
        if ($c->kind === 'bos' && isset($this->online[$c->nsn][$c->id])) {
            unset($this->online[$c->nsn][$c->id]);
            if (!$this->online[$c->nsn]) unset($this->online[$c->nsn]);
            if ($c->ready) $this->tellWatchers($c);
        }
    }

    private function tick(): void
    {
        $now = microtime(true);
        foreach ($this->conns as $c) {
            if ($now - $c->lastHeard > self::IDLE_TIMEOUT) $this->drop($c, 'silent too long');
            elseif ($c->kind !== 'bos' && $now - $c->opened > $this->limits['signon_timeout']) $this->drop($c, 'no sign-on in time');
        }
        foreach ($this->cookies as $k => $v) {
            if ($v['until'] < $now) unset($this->cookies[$k]);
        }
        if ($this->refused && $now - $this->lastRefusedLog > 60) {
            $this->log("refused {$this->refused} connections over the limits (" . count($this->conns) . " open)");
            $this->refused = 0;
            $this->lastRefusedLog = $now;
        }
        if ($now - $this->lastSweep > 3600) {
            $n = $this->data->sweepOffline();
            if ($n) $this->log("offline messages: $n expired");
            $this->lastSweep = $now;
        }
    }

    /** "1.2.3.4:5190" or "[2001:db8::1]:5190" -> the address alone. */
    private static function ipOf(string $peer): string
    {
        $at = strrpos($peer, ':');
        return trim($at === false ? $peer : substr($peer, 0, $at), '[]');
    }

    private function bySock($s): ?Conn
    {
        foreach ($this->conns as $c) if ($c->sock === $s) return $c;
        return null;
    }

    private function send(Conn $c, int $channel, string $data): void
    {
        $c->out .= Wire::flap($channel, $c->seq++, $data);
        $c->seq &= 0xffff;
        if (strlen($c->out) > self::MAX_UNREAD) $this->drop($c, 'not reading');
    }

    private function snac(Conn $c, int $fam, int $sub, string $body, int $reqid = 0, int $flags = 0): void
    {
        if ($this->logLevel >= self::LOG_DEBUG) $this->log(sprintf("#%d -> %02x,%02x (%d bytes)", $c->id, $fam, $sub, strlen($body)));
        $this->send($c, Wire::CH_SNAC, Wire::snac($fam, $sub, $flags, $reqid, $body));
    }

    // ---- frames ----

    private function frame(Conn $c, int $channel, string $payload): void
    {
        switch ($channel) {
            case Wire::CH_SIGNON:
                $r = new Reader($payload);
                if ($r->left() < 4 || $r->u32() !== 1) { $this->drop($c, 'bad FLAP version'); return; }
                $t = $r->tlvs();
                $cookie = $t->get(0x0006);
                if ($cookie !== null) $this->startBos($c, $cookie);
                else $c->kind = 'auth';
                return;
            case Wire::CH_SNAC:
                $r = new Reader($payload);
                $fam = $r->u16(); $sub = $r->u16(); $flags = $r->u16(); $reqid = $r->u32();
                if ($flags & 0x8000) { $r->raw($r->u16()); }     // optional SNAC TLV block
                if ($this->logLevel >= self::LOG_DEBUG) $this->log(sprintf("#%d <- %02x,%02x (%d bytes)", $c->id, $fam, $sub, $r->left()));
                if ($c->kind === 'auth' && $fam === 0x17) $this->auth($c, $sub, $reqid, $r);
                elseif ($c->kind === 'bos') { if ($this->rateOk($c, $fam, $reqid)) $this->bos($c, $fam, $sub, $reqid, $r); }
                else $this->log(sprintf("#%d SNAC %02x,%02x before sign-on, ignored", $c->id, $fam, $sub));
                return;
            case Wire::CH_SIGNOFF:
                $this->drop($c, 'signed off');
                return;
            case Wire::CH_KEEPALIVE:
                return;
            default:
                $this->log("#{$c->id} FLAP channel $channel ignored");
        }
    }

    // ---- authorizer (family 0x17, BUCP) ----

    private function auth(Conn $c, int $sub, int $reqid, Reader $r): void
    {
        $t = $r->tlvs();
        $sn = $t->get(0x0001) ?? '';
        if ($sub === 0x06) {                                 // key request
            $c->authKey = (string)random_int(1000000000, 2147483647);
            $this->snac($c, 0x17, 0x07, Wire::str16($c->authKey), $reqid);
            return;
        }
        if ($sub === 0x02) {                                 // login
            $hash = $t->get(0x0025);
            $display = ($hash !== null && $c->authKey !== null)
                ? $this->accounts->verify($sn, $c->authKey, $hash, $t->has(0x004C))
                : null;
            $reply = (new Tlvs())->add(0x0001, $display ?? $sn);
            if ($display === null) {
                $this->log("#{$c->id} sign-in refused for '$sn'");
                $reply->u16(0x0008, 0x0005)                   // incorrect username or password
                      ->add(0x0004, 'https://oscar.webosarchive.org/');
            } else {
                $cookie = random_bytes(32);
                $this->cookies[bin2hex($cookie)] = ['sn' => $display, 'until' => microtime(true) + 60];
                $reply->add(0x0005, $this->advertise)->add(0x0006, $cookie);
                $this->log("#{$c->id} '$display' signed in, sent to BOS");
            }
            $this->snac($c, 0x17, 0x03, $reply->encode(), $reqid);
            $c->closing = true;
            return;
        }
        $this->log(sprintf("#%d auth SNAC 17,%02x ignored", $c->id, $sub));
    }

    // ---- BOS ----

    private function startBos(Conn $c, string $cookie): void
    {
        $k = bin2hex($cookie);
        $entry = $this->cookies[$k] ?? null;
        unset($this->cookies[$k]);
        if (!$entry) {
            $this->send($c, Wire::CH_SIGNOFF, (new Tlvs())->u16(0x0009, 0x0000)->encode());
            $c->closing = true;
            $this->log("#{$c->id} unknown or expired cookie");
            return;
        }
        $c->kind = 'bos';
        $c->sn = $entry['sn'];
        $c->nsn = Wire::normalize($c->sn);
        $c->signon = time();

        // A screen name may be signed on in several places at once; each gets what is sent to
        // it. Past max_sessions the oldest is told it signed on elsewhere.
        $this->online[$c->nsn][$c->id] = $c;
        while (count($this->online[$c->nsn]) > $this->limits['max_sessions']) {
            $old = reset($this->online[$c->nsn]);
            unset($this->online[$c->nsn][$old->id]);
            $this->send($old, Wire::CH_SIGNOFF, (new Tlvs())->u16(0x0009, 0x0001)->encode());
            $old->closing = true;
            $old->ready = false;
            $this->log("#{$old->id} '{$old->sn}' signed off: too many sessions");
        }
        $c->buddies = array_fill_keys($this->data->buddiesOf($c->nsn), true);

        $this->snac($c, 0x01, 0x03, pack('n*', ...self::FAMILIES));
        $n = count($this->online[$c->nsn]);
        $this->log("#{$c->id} BOS session for '{$c->sn}'" . ($n > 1 ? " ($n sessions)" : ''));
    }

    private function bos(Conn $c, int $fam, int $sub, int $reqid, Reader $r): void
    {
        $k = ($fam << 16) | $sub;
        switch ($k) {
            // -- 0x01 generic service --
            case 0x00010017:                                     // client's family versions
                $pairs = '';
                while ($r->left() >= 4) {
                    $f = $r->u16(); $v = $r->u16();
                    if ($f === 0x0001) $c->oserviceVersion = $v;
                    $pairs .= pack('nn', $f, $v);
                }
                $this->snac($c, 0x01, 0x18, $pairs, $reqid);
                return;
            case 0x00010006:                                     // rate request
                $this->snac($c, 0x01, 0x07, $this->rates($c->oserviceVersion >= 3), $reqid);
                return;
            case 0x00010008:                                     // rate ack
                return;
            case 0x0001000E:                                     // own info
                $this->snac($c, 0x01, 0x0F, $this->userInfo($c), $reqid);
                return;
            case 0x00010004:                                     // new service (alerts, chat nav, icons)
                $this->snac($c, 0x01, 0x01, pack('n', 0x0005), $reqid);   // service unavailable
                return;
            case 0x00010011:                                     // idle time
                $secs = $r->u32();
                if (($secs > 0) !== ($c->idleSince > 0)) $this->vlog($c, $secs ? "idle for {$secs} s" : "no longer idle");
                $c->idleSince = $secs ? time() - $secs : 0;
                if ($c->ready) $this->tellWatchers($c);
                return;
            case 0x00010002:                                     // client ready
                $this->clientReady($c);
                return;
            case 0x0001001E:                                     // extended status
            case 0x00010014:                                     // privacy flags
                return;

            // -- 0x02 locate --
            case 0x00020002:
                $this->snac($c, 0x02, 0x03, (new Tlvs())->u16(0x0001, 4096)->u16(0x0002, 32)->encode(), $reqid);
                return;
            case 0x00020004:                                     // set profile / away / caps
                $t = $r->tlvs();
                if ($t->has(0x0002)) {
                    $c->profile = $t->get(0x0002); $c->profileEnc = $t->get(0x0001) ?? '';
                    $this->vlog($c, sprintf("profile set (%d bytes)", strlen($c->profile)));
                }
                if ($t->has(0x0005)) { $c->caps = $t->get(0x0005); }
                if ($t->has(0x0004)) {
                    $msg = $t->get(0x0004);
                    if (($msg === '') !== ($c->away === null)) $this->vlog($c, $msg === '' ? "back (away message cleared)" : sprintf("away (message of %d bytes)", strlen($msg)));
                    $c->away = $msg === '' ? null : $msg;
                    $c->awayEnc = $t->get(0x0003) ?? '';
                    if ($c->ready) $this->tellWatchers($c);
                }
                return;
            case 0x00020005:                                     // user info (old form)
                $type = $r->u16();
                $who = $r->str8();
                $this->vlog($c, "looked up '$who'");
                $this->sendUserInfo($c, $who, $type === 0x0003 ? 0x2 : 0x1, $reqid);
                return;
            case 0x00020015:                                     // user info
                $flags = $r->u32();
                $who = $r->str8();
                $this->vlog($c, "looked up '$who'");
                $this->sendUserInfo($c, $who, $flags, $reqid);
                return;
            case 0x0002000B:                                     // directory info: not kept
                return;

            // -- 0x03 buddy --
            case 0x00030002:
                $this->snac($c, 0x03, 0x03, (new Tlvs())->u16(0x0001, 1000)->u16(0x0002, 3000)->u16(0x0003, 512)->encode(), $reqid);
                return;
            case 0x00030004:                                     // add buddies (a list kept by the client)
            case 0x00030005:                                     // remove buddies
                // Clients that keep their buddy list themselves send it here instead of
                // editing the feedbag; without it, nobody they add ever shows online to them.
                while ($r->left() > 0) {
                    $b = Wire::normalize($r->str8());
                    if ($b === '') continue;
                    if ($sub === 0x05) { unset($c->buddies[$b]); $this->vlog($c, "buddy '$b' removed (client list)"); continue; }
                    if (!isset($c->buddies[$b]) && count($c->buddies) >= self::MAX_FEEDBAG_ITEMS) { $this->vlog($c, "buddy '$b' refused: list full"); break; }
                    $c->buddies[$b] = true;
                    $this->vlog($c, "buddy '$b' added (client list)");
                    $o = $this->presence($b);
                    if ($c->ready && $o) $this->snac($c, 0x03, 0x0B, $this->userInfo($o));
                }
                return;

            // -- 0x04 ICBM --
            case 0x00040002:                                     // set parameters
                return;
            case 0x00040004:
                // channel, flags, max message, max sender/receiver warning, min interval
                $this->snac($c, 0x04, 0x05, pack('nNnnnN', 0x0005, 0x0000000B, 8000, 999, 999, 0), $reqid);
                return;
            case 0x00040006:
                $this->im($c, $r, $reqid);
                return;
            case 0x00040010:                                     // offline messages: sent at client ready
                $this->snac($c, 0x04, 0x17, '', $reqid);
                return;
            case 0x00040014:
                $this->typing($c, $r);
                return;

            // -- 0x09 privacy (BOS) --
            case 0x00090002:
                $this->snac($c, 0x09, 0x03, (new Tlvs())->u16(0x0001, 1000)->u16(0x0002, 1000)->encode(), $reqid);
                return;

            // -- 0x13 feedbag (server-stored buddy list) --
            case 0x00130002:
                $max = pack('n*', 1000, 100, 1000, 1000, 1, 1, 50, 50, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1);
                $this->snac($c, 0x13, 0x03, (new Tlvs())->add(0x0004, $max)->encode(), $reqid);
                return;
            case 0x00130004:
            case 0x00130005:
                $this->vlog($c, sprintf("buddy list sent (%d items)", count($this->data->feedbag($c->nsn)['items'])));
                $this->snac($c, 0x13, 0x06, $this->feedbagList($c), $reqid);
                return;
            case 0x00130007:                                     // activate
                return;
            case 0x00130008:
            case 0x00130009:
            case 0x0013000A:
                $this->feedbagEdit($c, $sub, $r, $reqid);
                return;
            case 0x00130011:
            case 0x00130012:                                     // edit start / stop
                return;
        }
        $this->log(sprintf("#%d %s SNAC %02x,%02x ignored", $c->id, $c->sn, $fam, $sub));
    }

    // ---- sign-on and presence ----

    private function clientReady(Conn $c): void
    {
        if ($c->ready) return;
        $c->ready = true;
        foreach (array_keys($c->buddies) as $b) {
            $o = $this->presence($b);
            if ($o) $this->snac($c, 0x03, 0x0B, $this->userInfo($o));
        }
        $this->tellWatchers($c);
        $offline = $this->data->takeOffline($c->nsn);
        if ($offline) $this->vlog($c, sprintf("%d offline message(s) delivered", count($offline)));
        foreach ($offline as $m) {
            $this->snac($c, 0x04, 0x07, hex2bin($m['cookie']) . pack('n', $m['channel'])
                . $this->userInfoOffline($m['from']) . hex2bin($m['tlvs']) . (new Tlvs())->u32(0x0016, $m['time'])->encode());
        }
        $this->log("#{$c->id} '{$c->sn}' is online (" . count($this->online) . " online)");
    }

    /** The ready sessions of a screen name. @return Conn[] */
    private function sessions(string $nsn): array
    {
        return array_filter($this->online[$nsn] ?? [], fn($o) => $o->ready);
    }

    /**
     * The ready session that speaks for a screen name to everyone else: one that isn't away
     * over one that is, then the least idle, then the newest. Null when it isn't online.
     */
    private function presence(string $nsn): ?Conn
    {
        $rank = fn(Conn $o) => [$o->away === null, $o->idleSince ?: PHP_INT_MAX, $o->id];
        $best = null;
        foreach ($this->sessions($nsn) as $o) if (!$best || $rank($o) > $rank($best)) $best = $o;
        return $best;
    }

    /**
     * Tells everyone who has $c's screen name as a buddy how it now looks after $c came,
     * changed, or went: online as the session that speaks for it, or gone with its last one.
     */
    private function tellWatchers(Conn $c): void
    {
        $u = $this->presence($c->nsn);
        foreach ($this->online as $list) foreach ($list as $o) {
            if (!$o->ready || !isset($o->buddies[$c->nsn]) || $o->nsn === $c->nsn) continue;
            if ($u) $this->snac($o, 0x03, 0x0B, $this->userInfo($u));
            else $this->snac($o, 0x03, 0x0C, Wire::str8($c->sn) . pack('nn', 0, 0));
        }
    }

    /** The user info block: name, warning level, then a counted TLV list. */
    private function userInfo(Conn $u): string
    {
        $class = 0x0010 | ($u->away !== null ? 0x0020 : 0);   // AIM user, away
        // Online since the first of its sessions, so another sign-in isn't a new arrival.
        $since = min([$u->signon, ...array_map(fn($o) => $o->signon, $this->sessions($u->nsn))]);
        $t = (new Tlvs())->u16(0x0001, $class)->u32(0x0003, $since)->u32(0x0005, $since);
        if ($u->idleSince) $t->u16(0x0004, intdiv(time() - $u->idleSince, 60));
        if ($u->caps !== '') $t->add(0x000D, $u->caps);
        return Wire::str8($u->sn) . pack('n', 0) . $t->encodeCounted();
    }

    private function userInfoOffline(string $sn): string
    {
        return Wire::str8($sn) . pack('n', 0) . (new Tlvs())->u16(0x0001, 0x0010)->encodeCounted();
    }

    private function sendUserInfo(Conn $c, string $who, int $flags, int $reqid): void
    {
        $nsn = Wire::normalize($who);
        $o = $this->presence($nsn);
        if (!$o) {
            $this->snac($c, 0x02, 0x01, pack('n', 0x0004), $reqid);   // not logged in
            return;
        }
        // The profile from the session that has one, should the one speaking not.
        $p = $o;
        if ($p->profile === '') foreach ($this->sessions($nsn) as $s) if ($s->profile !== '') { $p = $s; break; }
        $t = new Tlvs();
        if ($flags & 0x1) $t->add(0x0001, $p->profileEnc ?: 'text/aolrtf; charset="us-ascii"')->add(0x0002, $p->profile);
        if (($flags & 0x2) && $o->away !== null) $t->add(0x0003, $o->awayEnc ?: 'text/aolrtf; charset="us-ascii"')->add(0x0004, $o->away);
        if ($flags & 0x4) $t->add(0x0005, $o->caps);
        $this->snac($c, 0x02, 0x06, $this->userInfo($o) . $t->encode(), $reqid);
    }

    // ---- messages ----

    private function im(Conn $c, Reader $r, int $reqid): void
    {
        $cookie = $r->raw(8);
        $channel = $r->u16();
        $to = $r->str8();
        $t = $r->tlvs();

        // Forward everything but the sender-side flags (0x03 ack request, 0x06 store offline).
        $fwd = new Tlvs();
        foreach ($t->list as [$type, $v]) if ($type !== 0x0003 && $type !== 0x0006) $fwd->add($type, $v);

        // Who, to whom, how big and what became of it: never the text.
        $about = sprintf("message to '%s' (channel %d, %d bytes)", $to, $channel, strlen($t->get(0x0002) ?? $t->get(0x0005) ?? ''));
        if ($channel === 1 && strlen($t->get(0x0002) ?? '') > self::MAX_MESSAGE + 64) {
            $this->vlog($c, "$about refused: too long");
            $this->snac($c, 0x04, 0x01, pack('n', 0x000E), $reqid);   // incorrect SNAC format
            return;
        }
        $all = $this->sessions(Wire::normalize($to));
        if ($all) {
            $this->vlog($c, "$about delivered" . (count($all) > 1 ? " to " . count($all) . " sessions" : ''));
            $msg = $cookie . pack('n', $channel) . $this->userInfo($c) . $fwd->encode();
            foreach ($all as $o) $this->snac($o, 0x04, 0x07, $msg);
            if ($t->has(0x0003)) $this->snac($c, 0x04, 0x0C, $cookie . pack('n', $channel) . Wire::str8($to), $reqid);
            return;
        }
        // Kept for a screen name that is an account, up to UserData's limits.
        if ($channel === 1 && $t->has(0x0006) && $this->accounts->exists($to)) {
            $kept = $this->data->queueOffline(Wire::normalize($to), [
                'from' => $c->sn, 'cookie' => bin2hex($cookie), 'channel' => $channel,
                'tlvs' => bin2hex($fwd->encode()), 'time' => time(),
            ]);
            if (!$kept) {
                $this->vlog($c, "$about refused: recipient's offline queue is full");
                $this->snac($c, 0x04, 0x01, pack('n', 0x0017), $reqid);   // server queue full
                return;
            }
            $this->vlog($c, "$about stored offline");
            if ($t->has(0x0003)) $this->snac($c, 0x04, 0x0C, $cookie . pack('n', $channel) . Wire::str8($to), $reqid);
            return;
        }
        $this->vlog($c, "$about refused: recipient offline" . ($t->has(0x0006) ? " and not an account" : ", not stored (the sender didn't ask)"));
        $this->snac($c, 0x04, 0x01, pack('n', 0x0004), $reqid);        // recipient not logged in
    }

    private function typing(Conn $c, Reader $r): void
    {
        $cookie = $r->raw(8);
        $channel = $r->u16();
        $to = $r->str8();
        $event = $r->u16();
        foreach ($this->sessions(Wire::normalize($to)) as $o)
            $this->snac($o, 0x04, 0x14, $cookie . pack('n', $channel) . Wire::str8($c->sn) . pack('n', $event));
    }

    // ---- feedbag ----

    private function feedbagList(Conn $c): string
    {
        $fb = $this->data->feedbag($c->nsn);
        $body = chr(0) . pack('n', count($fb['items']));
        foreach ($fb['items'] as $it) $body .= $this->encodeItem($it);
        return $body . pack('N', $fb['stamp'] ?: time());
    }

    private function encodeItem(array $it): string
    {
        $tl = hex2bin($it['tlvs']);
        return Wire::str16($it['name']) . pack('nnnn', $it['gid'], $it['bid'], $it['type'], strlen($tl)) . $tl;
    }

    private function feedbagEdit(Conn $c, int $sub, Reader $r, int $reqid): void
    {
        $fb = $this->data->feedbag($c->nsn);
        $acks = $done = '';
        // The user's other sessions see the edit too, as AOL's servers sent it to them.
        $others = array_filter($this->sessions($c->nsn), fn($o) => $o !== $c);
        while ($r->left() >= 10) {
            $name = $r->str16();
            $gid = $r->u16(); $bid = $r->u16(); $type = $r->u16();
            $tl = $r->raw($r->u16());
            $item = ['name' => $name, 'gid' => $gid, 'bid' => $bid, 'type' => $type, 'tlvs' => bin2hex($tl)];
            $fb['items'] = array_values(array_filter($fb['items'],
                fn($i) => !($i['gid'] === $gid && $i['bid'] === $bid)));
            $what = self::FEEDBAG_TYPES[$type] ?? sprintf("item of type %d", $type);
            $op = [0x08 => 'added', 0x09 => 'changed', 0x0A => 'removed'][$sub] ?? 'edited';
            if ($sub !== 0x0A && (count($fb['items']) >= self::MAX_FEEDBAG_ITEMS || strlen($tl) > self::MAX_FEEDBAG_TLVS)) {
                $acks .= pack('n', 0x000C);                      // limit exceeded
                $this->vlog($c, "$what '$name' refused: buddy list limit");
                continue;
            }
            $this->vlog($c, $name === '' ? "$what $op (group $gid, item $bid)" : "$what '$name' $op (group $gid)");
            if ($sub !== 0x0A) $fb['items'][] = $item;
            $acks .= pack('n', 0x0000);
            $done .= $this->encodeItem($item);

            if ($type === 0x0000 && $name !== '') {
                $b = Wire::normalize($name);
                $o = $this->presence($b);
                foreach ([$c, ...$others] as $s) {
                    if ($sub === 0x0A) { unset($s->buddies[$b]); continue; }
                    $known = isset($s->buddies[$b]);
                    $s->buddies[$b] = true;
                    if ($o && ($sub === 0x08 || !$known)) $this->snac($s, 0x03, 0x0B, $this->userInfo($o));
                }
            }
        }
        $this->data->saveFeedbag($c->nsn, $fb);
        $this->snac($c, 0x13, 0x0E, $acks, $reqid);
        if ($done !== '') foreach ($others as $s) $this->snac($s, 0x13, $sub, $done);
    }

    /** Every SNAC a client sends, so each has a rate class (libpurple 2.5.1 warns of any without). */
    private const RATED = [
        0x01 => [0x02, 0x04, 0x06, 0x08, 0x0E, 0x11, 0x14, 0x17, 0x1E],
        0x02 => [0x02, 0x04, 0x05, 0x0B, 0x15],
        0x03 => [0x02, 0x04, 0x05],
        0x04 => [0x02, 0x04, 0x06, 0x08, 0x0B, 0x10, 0x14],
        0x09 => [0x02, 0x05, 0x06, 0x07, 0x08],
        0x13 => [0x02, 0x04, 0x05, 0x07, 0x08, 0x09, 0x0A, 0x11, 0x12, 0x14, 0x18, 0x1A],
    ];

    /**
     * Applies the rate class to a SNAC from a BOS session, as AOL's servers did: under the
     * limit mark its SNACs are refused (error 0x0002, rate to host) until the level climbs
     * back over the clear mark; under the disconnect mark the session is dropped.
     */
    private function rateOk(Conn $c, int $fam, int $reqid): bool
    {
        $now = microtime(true) * 1000;
        $delta = $c->rateLast ? $now - $c->rateLast : self::RATE_MAX;
        $c->rateLast = $now;
        $c->rateLevel = min(self::RATE_MAX, ($c->rateLevel * (self::RATE_WINDOW - 1) + $delta) / self::RATE_WINDOW);
        if ($c->rateLevel < self::RATE_DISCONNECT) {
            $this->flush($c);                                // one try at the replies it has coming
            $this->drop($c, 'over the rate limit');
            return false;
        }
        if ($c->rateLimited && $c->rateLevel >= self::RATE_CLEAR) {
            $c->rateLimited = false;
        } elseif (!$c->rateLimited && $c->rateLevel < self::RATE_LIMIT) {
            $c->rateLimited = true;
            $this->log("#{$c->id} '{$c->sn}' rate limited");
        }
        if (!$c->rateLimited) return true;
        $this->snac($c, $fam, 0x01, pack('n', 0x0002), $reqid);
        return false;
    }

    /**
     * Rate classes: one generous class (id 1) with every SNAC in it. Version 3 adds a time
     * delta and a dropping flag to each class.
     */
    private function rates(bool $v3): string
    {
        $class = pack('nNNNNNNN', 1, self::RATE_WINDOW, self::RATE_CLEAR, self::RATE_ALERT, self::RATE_LIMIT,
            self::RATE_DISCONNECT, self::RATE_INITIAL, self::RATE_MAX);
        if ($v3) $class .= pack('N', 0) . chr(0);
        $members = '';
        $n = 0;
        foreach (self::RATED as $fam => $subs) foreach ($subs as $sub) { $members .= pack('nn', $fam, $sub); $n++; }
        return pack('n', 1) . $class . pack('nn', 1, $n) . $members;
    }

    public const LOG_NORMAL = 0;    // connections, sign-ins, refusals, limits
    public const LOG_VERBOSE = 1;   // and what each session does: buddies, messages, away, idle
    public const LOG_DEBUG = 2;     // and every SNAC in and out

    private const FEEDBAG_TYPES = [0x0000 => 'buddy', 0x0001 => 'group', 0x0002 => 'permit entry',
        0x0003 => 'deny entry', 0x0004 => 'privacy setting', 0x0005 => 'presence setting', 0x0014 => 'buddy icon'];

    /** A session event, at the verbose level. Message text is never logged. */
    private function vlog(Conn $c, string $m): void
    {
        if ($this->logLevel >= self::LOG_VERBOSE) $this->log("#{$c->id} '{$c->sn}' $m");
    }

    private function log(string $m): void
    {
        fwrite(STDERR, date('H:i:s') . " $m\n");
    }
}
