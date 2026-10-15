<?php
/**
 * Checks the server's limits against a running oscard started with small ones:
 *
 *   OSCAR_LISTEN=127.0.0.1:5199 OSCAR_ADVERTISE=127.0.0.1:5199 OSCAR_DATA_DIR=<dir>
 *   OSCAR_MAX_PER_IP=4 OSCAR_SIGNON_TIMEOUT=3 OSCAR_OFFLINE_MAX=3 OSCAR_MAX_SESSIONS=2 OSCAR_AUTO_BUDDIES=off php server/bin/oscard
 *
 * with alice and bob in <dir>/accounts.json, both with the pass phrase "maple river lamp".
 * Run: php test/limits.php 127.0.0.1:5199
 */
require_once __DIR__ . '/lib.php';

$addr = $argv[1] ?? '127.0.0.1:5199';

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

// 3b. A buddy list kept by the client (03,04, no feedbag): adding someone online shows them.
$alice->sendSnac(0x03, 0x04, Wire::str8('bob'), 30);
$seen = false;
while ($s = $alice->snac(1)) if ($s[0] === 0x03 && $s[1] === 0x0B) $seen = true;
check($seen, "a buddy added with 03,04 is reported online");

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

// 6. bob is now signed on twice ($bob and $carol): neither signs the other off, both get
// his messages, and he stays online to his watchers until the last session ends.
while ($bob->snac(0.5));
while ($carol->snac(0.5));
check(!$bob->closed(1), "bob's first session stays open beside the second");
$alice = signIn($addr, 'alice');
$alice->sendSnac(0x03, 0x04, Wire::str8('bob'), 40);
while ($alice->snac(1));
$alice->sendSnac(0x04, 0x06, im('bob', 'to both', false), 41);
$got = 0;
foreach ([$bob, $carol] as $c) while ($s = $c->snac(1)) if ($s[0] === 0x04 && $s[1] === 0x07) $got++;
check($got === 2, "a message to bob reaches $got of his 2 sessions");

// 7. A third session is one past max_sessions: the oldest is told it signed on elsewhere.
$dave = signIn($addr, 'bob');
$f = $bob->frame(2);
check($f && $f[0] === Wire::CH_SIGNOFF && (new Reader($f[1]))->tlvs()->get(0x0009) === pack('n', 1),
    "past max_sessions the oldest session is signed off as signed on elsewhere");
check($bob->closed(2), "and closed");
fclose($carol->s);
$gone = $still = false;
while ($s = $alice->snac(1)) { if ($s[0] === 0x03 && $s[1] === 0x0C) $gone = true; if ($s[0] === 0x03 && $s[1] === 0x0B) $still = true; }
check(!$gone && $still, "bob ending one session leaves him online");
fclose($dave->s);
$gone = false;
while ($s = $alice->snac(1)) if ($s[0] === 0x03 && $s[1] === 0x0C) $gone = true;
check($gone, "bob ending his last session signs him off");

echo $fails ? "$fails failed\n" : "all passed\n";
exit($fails ? 1 : 0);
