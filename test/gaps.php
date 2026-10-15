<?php
/**
 * Checks that messages get through gaps in a connection: keepalives, the idle timeout,
 * messages to someone offline, and messages sent to a connection that died. Against a
 * running oscard with short timeouts, with PHP's sockets extension:
 *
 *   OSCAR_LISTEN=127.0.0.1:5199 OSCAR_ADVERTISE=127.0.0.1:5199 OSCAR_DATA_DIR=<dir>
 *   OSCAR_IDLE_TIMEOUT=4 OSCAR_KEEPALIVE=1 OSCAR_DELIVERY_TIMEOUT=1 OSCAR_AUTO_BUDDIES=off php server/bin/oscard
 *
 * with alice and bob in <dir>/accounts.json, both with the pass phrase "maple river lamp".
 * Run as root where iptables works (the dead connection is made by dropping its packets):
 * php test/gaps.php 127.0.0.1:5199
 */
require_once __DIR__ . '/lib.php';

$addr = $argv[1] ?? '127.0.0.1:5199';

/** An instant message without the "store offline" flag, as libpurple sends to a buddy online. */
function im(string $to, string $text): string
{
    $msg = (new Tlvs())->add(0x0501, "\x01")->add(0x0101, "\0\0\0\0" . $text)->encode();
    return random_bytes(8) . pack('n', 1) . Wire::str8($to) . (new Tlvs())->add(0x0002, $msg)->add(0x0003, '')->encode();
}

/** The texts of the messages that come within $wait seconds, skipping everything else. */
function texts(Client $c, float $wait = 1): array
{
    $out = [];
    while ($s = $c->snac($wait)) {
        if ($s[0] !== 0x04 || $s[1] !== 0x07) continue;
        $r = new Reader($s[2]);
        $r->raw(10); $r->str8(); $r->u16(); $r->tlvsCounted();
        $m = (new Reader($r->tlvs()->get(0x0002) ?? ''))->tlvs()->get(0x0101);
        $out[] = substr((string)$m, 4);
    }
    return $out;
}

/** Sends alice's message and returns 'ack' or what came instead. */
function sendIm(Client $from, string $to, string $text): string
{
    $from->sendSnac(0x04, 0x06, im($to, $text), 10);
    while ($s = $from->snac()) {
        if ($s[0] === 0x04 && $s[1] === 0x0C) return 'ack';
        if ($s[1] === 0x01) return sprintf('error %04x', unpack('n', $s[2])[1]);
    }
    return 'nothing';
}

// 1. A quiet session is sent keepalives, and dropped only after idle_timeout.
$bob = signIn($addr, 'bob');
$keepalives = 0;
$until = microtime(true) + 2.5;
while (($left = $until - microtime(true)) > 0) if (($f = $bob->frame($left)) && $f[0] === Wire::CH_KEEPALIVE) $keepalives++;
check($keepalives >= 1, "a quiet session is sent keepalives ($keepalives in 2.5 s)");
check($bob->closed(4), "and is dropped once it has sent nothing for idle_timeout");

// 2. Messages to someone offline are kept, whether or not the sender asked: just after the
// connection went, and after signing off.
$alice = signIn($addr, 'alice');
while ($alice->snac(0.3));
check(($x = sendIm($alice, 'bob', 'one')) === 'ack', "a message to someone whose connection just went is accepted: $x");
$bob = signIn($addr, 'bob');
check(($x = texts($bob)) === ['one'], "and delivered when he signs back in: " . implode(', ', $x));
$bob->send(Wire::CH_SIGNOFF, '');
usleep(300000);
check(($x = sendIm($alice, 'bob', 'two')) === 'ack', "a message to someone who signed off is accepted: $x");
$bob = signIn($addr, 'bob');
check(($x = texts($bob)) === ['two'], "and delivered when he signs back in: " . implode(', ', $x));

// 3. A message the device's TCP acknowledged before the server dropped its connection has
// arrived, and isn't delivered again.
sendIm($alice, 'bob', 'three');
$t0 = microtime(true);
$closed = false;
while (!$closed && microtime(true) - $t0 < 8) { $alice->send(Wire::CH_KEEPALIVE, ''); $closed = $bob->closed(1); }
check($closed, "bob, silent, is dropped");
$bob = signIn($addr, 'bob');
check(($x = texts($bob)) === [], "a message that was acknowledged isn't delivered twice: " . implode(', ', $x));

// 4. A connection that dies without a word (a phone out of coverage): what is sent to it is
// never acknowledged, the server gives it up after delivery_timeout, and the message comes
// at the next sign-on.
$port = (int)substr(strrchr(stream_socket_get_name($bob->s, false), ':'), 1);
[$host, $sport] = explode(':', $addr);
$rule = "OUTPUT -p tcp -s $host --sport $sport -d 127.0.0.1 --dport $port -j DROP";
if (@exec("iptables -A $rule 2>&1", $o, $rc) === false || $rc !== 0) {
    echo "skip a connection that dies: iptables unavailable (" . implode(' ', $o) . ")\n";
} else {
    $alice->sendSnac(0x03, 0x04, Wire::str8('bob'), 30);     // to see him go
    while ($alice->snac(0.3));
    $t0 = microtime(true);
    check(sendIm($alice, 'bob', 'four') === 'ack', "a message to bob on his dead connection is accepted");
    // bob's side still talks, so only the unacknowledged message can end the connection.
    $gone = false;
    while (microtime(true) - $t0 < 10) {
        $bob->send(Wire::CH_KEEPALIVE, '');
        $alice->send(Wire::CH_KEEPALIVE, '');
        if (($s = $alice->snac(1)) && $s[0] === 0x03 && $s[1] === 0x0C) { $gone = true; break; }
    }
    exec("iptables -D $rule");
    check($gone, sprintf("the dead connection is given up (%.1f s)", microtime(true) - $t0));
    $bob = signIn($addr, 'bob');
    check(($x = texts($bob)) === ['four'], "and the message comes at bob's next sign-on: " . implode(', ', $x));
}

echo $fails ? "$fails failed\n" : "all passed\n";
exit($fails ? 1 : 0);
