<?php
/** What the server tests share: check(), a FLAP client, and signing in as an account whose pass phrase is "maple river lamp". */
require_once __DIR__ . '/../server/src/Wire.php';

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
        // Without Nagle, so what is sent goes at once even while earlier bytes are unacknowledged.
        $this->s = stream_socket_client("tcp://$addr", $e, $es, 5, STREAM_CLIENT_CONNECT,
            stream_context_create(['socket' => ['tcp_nodelay' => true]]));
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
