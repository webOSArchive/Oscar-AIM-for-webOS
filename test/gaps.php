<?php
/**
 * Checks keepalives, the idle timeout and messages to a screen name that just lost its
 * connection, against a running oscard with short timeouts:
 *
 *   OSCAR_LISTEN=127.0.0.1:5199 OSCAR_ADVERTISE=127.0.0.1:5199 OSCAR_DATA_DIR=<dir>
 *   OSCAR_IDLE_TIMEOUT=4 OSCAR_KEEPALIVE=1 OSCAR_AUTO_BUDDIES=off php server/bin/oscard
 *
 * with alice and bob in <dir>/accounts.json, both with the pass phrase "maple river lamp".
 * Run: php test/gaps.php 127.0.0.1:5199
 */
require_once __DIR__ . '/lib.php';

$addr = $argv[1] ?? '127.0.0.1:5199';

/** An instant message without the "store offline" flag, as libpurple sends to a buddy online. */
function im(string $to, string $text): string
{
    $msg = (new Tlvs())->add(0x0501, "\x01")->add(0x0101, "\0\0\0\0" . $text)->encode();
    return random_bytes(8) . pack('n', 1) . Wire::str8($to) . (new Tlvs())->add(0x0002, $msg)->add(0x0003, '')->encode();
}

// 1. A quiet session is sent keepalives, and dropped only after idle_timeout.
$bob = signIn($addr, 'bob');
$keepalives = 0;
$until = microtime(true) + 2.5;
while (($left = $until - microtime(true)) > 0) if (($f = $bob->frame($left)) && $f[0] === Wire::CH_KEEPALIVE) $keepalives++;
check($keepalives >= 1, "a quiet session is sent keepalives ($keepalives in 2.5 s)");
check($bob->closed(4), "and is dropped once it has sent nothing for idle_timeout");

// 2. A message to bob just after his connection went is kept, though alice didn't ask, and
// he gets it when he is back.
$alice = signIn($addr, 'alice');
while ($alice->snac(0.3));
$alice->sendSnac(0x04, 0x06, im('bob', 'are you there'), 10);
$s = $alice->snac();
check($s && $s[0] === 0x04 && $s[1] === 0x0C, "a message to someone whose connection just went is accepted");
$bob = signIn($addr, 'bob');
$got = 0;
while ($s = $bob->snac(1)) if ($s[0] === 0x04 && $s[1] === 0x07) $got++;
check($got === 1, "and delivered when he signs back in ($got)");

// 3. Someone who signed off is offline: no grace.
$bob->send(Wire::CH_SIGNOFF, '');
usleep(300000);
$alice->sendSnac(0x04, 0x06, im('bob', 'still there?'), 11);
$s = $alice->snac();
check($s && $s[1] === 0x01 && unpack('n', $s[2])[1] === 0x0004, "a message to someone who signed off is refused as before");

echo $fails ? "$fails failed\n" : "all passed\n";
exit($fails ? 1 : 0);
