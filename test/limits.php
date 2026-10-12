<?php
/**
 * Checks the server's limits against a running oscard started with small ones:
 *
 *   OSCAR_LISTEN=127.0.0.1:5199 OSCAR_ADVERTISE=127.0.0.1:5199 OSCAR_DATA_DIR=<dir>
 *   OSCAR_MAX_PER_IP=4 OSCAR_SIGNON_TIMEOUT=3 OSCAR_OFFLINE_MAX=3 php server/bin/oscard
 *
 * with alice and bob in <dir>/accounts.json, both with the pass phrase "maple river lamp".
 * Run: php test/limits.php 127.0.0.1:5199
 */
require_once __DIR__ . '/../server/src/Wire.php';

$addr = $argv[1] ?? '127.0.0.1:5199';
$fails = 0;
function check(bool $ok, string $what): void
{
    global $fails;
    echo ($ok ? "ok   " : "FAIL ") . "$what\n";
    if (!$ok) $fails++;
}

final class Client
{
    public $s;
    private string $buf = '';
    private int $seq = 1;

    public function __construct(string $addr)
    {
        $this->s = stream_socket_client("tcp://$addr", $e, $es, 5);
        if (!$this->s) throw new RuntimeException("connect: $es");
    }

    /** The next frame as [channel, payload], or null when closed or after $timeout s. */
    public function frame(float $timeout = 2): ?array
    {
        $until = microtime(true) + $timeout;
        while (true) {
            if (strlen($this->buf) >= 6) {
                $len = unpack('n', $this->buf, 4)[1];
                if (strlen($this->buf) >= 6 + $len) {
                    $f = [ord($this->buf[1]), substr($this->buf, 6, $len)];
                    $this->buf = substr($this->buf, 6 + $len);
                    return $f;
                }
            }
            $left = $until - microtime(true);
            if ($left <= 0) return null;
            $r = [$this->s]; $w = $x = null;
            if (!stream_select($r, $w, $x, 0, (int)($left * 1e6))) return null;
            $d = fread($this->s, 65536);
            if ($d === '' || $d === false) return null;
            $this->buf .= $d;
        }
    }

    /** The next SNAC as [family, subtype, body], skipping other channels. */
    public function snac(float $timeout = 2): ?array
    {
        while ($f = $this->frame($timeout)) {
            if ($f[0] !== Wire::CH_SNAC) continue;
            $h = unpack('nfam/nsub/nflags/Nreq', $f[1]);
            return [$h['fam'], $h['sub'], substr($f[1], 10)];
        }
        return null;
    }

    public function closed(float $timeout): bool
    {
        $until = microtime(true) + $timeout;
        while (microtime(true) < $until) {
            $r = [$this->s]; $w = $x = null;
            if (stream_select($r, $w, $x, 0, 200000)) {
                $d = fread($this->s, 65536);
                if ($d === '' || $d === false) return feof($this->s);
            }
        }
        return false;
    }

    public function send(int $ch, string $data): void
    {
        fwrite($this->s, Wire::flap($ch, $this->seq++, $data));
    }

    public function sendSnac(int $fam, int $sub, string $body, int $req = 1): void
    {
        $this->send(Wire::CH_SNAC, Wire::snac($fam, $sub, 0, $req, $body));
    }
}

/** Signs in through the authorizer and opens the BOS session; returns the BOS client. */
function signIn(string $addr, string $sn): Client
{
    $a = new Client($addr);
    $a->frame();
    $a->send(Wire::CH_SIGNON, pack('N', 1));
    $a->sendSnac(0x17, 0x06, (new Tlvs())->add(0x0001, $sn)->encode());
    [, , $body] = $a->snac();
    $key = (new Reader($body))->str16();
    $hash = md5($key . md5('maple river lamp', true) . 'AOL Instant Messenger (SM)', true);
    $a->sendSnac(0x17, 0x02, (new Tlvs())->add(0x0001, $sn)->add(0x0025, $hash)->add(0x004C, '')->encode());
    [, , $body] = $a->snac();
    $cookie = (new Reader($body))->tlvs()->get(0x0006);
    if ($cookie === null) throw new RuntimeException("$sn: sign-in refused");
    $b = new Client($addr);
    $b->frame();
    $b->send(Wire::CH_SIGNON, pack('N', 1) . (new Tlvs())->add(0x0006, $cookie)->encode());
    $b->snac();                                   // 01,03 families
    $b->sendSnac(0x01, 0x02, '');                 // client ready
    return $b;
}

function im(string $to, string $text, bool $store = true): string
{
    $msg = (new Tlvs())->add(0x0501, "\x01")->add(0x0101, "\0\0\0\0" . $text)->encode();
    $t = (new Tlvs())->add(0x0002, $msg)->add(0x0003, '');
    if ($store) $t->add(0x0006, '');
    return random_bytes(8) . pack('n', 1) . Wire::str8($to) . $t->encode();
}

/** The reply to the next request: 'ack', 'error XXXX' or what else came. */
function reply(Client $c): string
{
    $s = $c->snac();
    if (!$s) return 'nothing';
    if ($s[1] === 0x0C) return 'ack';
    if ($s[1] === 0x01) return sprintf('error %04x', unpack('n', $s[2])[1]);
    return sprintf('%02x,%02x', $s[0], $s[1]);
}

// 1. Per-address limit and the sign-on timeout.
$open = [];
for ($i = 0; $i < 6; $i++) $open[] = new Client($addr);
$hello = 0;
foreach ($open as $c) if ($c->frame(1)) $hello++;
check($hello === 4, "per-address limit: $hello of 6 connections served (4 allowed)");
$t0 = microtime(true);
$shut = 0;
foreach ($open as $c) if ($c->closed(5)) $shut++;
check($shut === 6, sprintf("connections that never sign in are closed (%d of 6, by %.1f s)", $shut, microtime(true) - $t0));
unset($open);

// 2. Offline messages: only for accounts, at most offline_max each, size-checked.
$alice = signIn($addr, 'alice');
$r = [];
for ($i = 0; $i < 4; $i++) { $alice->sendSnac(0x04, 0x06, im('bob', "hello $i"), 10 + $i); $r[] = reply($alice); }
check($r === ['ack', 'ack', 'ack', 'error 0017'], "offline queue for bob: " . implode(', ', $r));
$alice->sendSnac(0x04, 0x06, im('nobody', 'hi'), 20);
check(($x = reply($alice)) === 'error 0004', "offline message to a name that isn't an account: $x");
$alice->sendSnac(0x04, 0x06, im('bob', str_repeat('x', 9000)), 21);
check(($x = reply($alice)) === 'error 000e', "9000-byte message: $x");

// 3. bob signs in and gets the three kept messages.
$bob = signIn($addr, 'bob');
$got = 0;
while ($s = $bob->snac(1)) if ($s[0] === 0x04 && $s[1] === 0x07) $got++;
check($got === 3, "bob receives $got offline messages");

// 4. The rate class: typing notifications as fast as they can go.
$typing = random_bytes(8) . pack('n', 1) . Wire::str8('bob') . pack('n', 2);
$firstLimited = null;
for ($i = 1; $i <= 200; $i++) {
    $alice->sendSnac(0x04, 0x14, $typing, 1000 + $i);
    if (@fwrite($alice->s, '') === false) break;
}
$n = 0; $errors = 0;
while ($s = $alice->snac(2)) { $n++; if ($s[1] === 0x01) { $errors++; } }
check($errors > 0, "flooding: $errors SNACs refused with rate errors");
check($alice->closed(3), "flooding past the disconnect mark closes the session");

// 5. An honest pace is never limited.
$carol = signIn($addr, 'bob');               // bob again: a new session
$bad = 0;
for ($i = 0; $i < 12; $i++) {
    $carol->sendSnac(0x04, 0x14, $typing, 2000 + $i);
    usleep(300000);
}
while ($s = $carol->snac(1)) if ($s[1] === 0x01) $bad++;
check($bad === 0, "a SNAC every 0.3 s for 12: $bad refused");

echo $fails ? "$fails failed\n" : "all passed\n";
exit($fails ? 1 : 0);
