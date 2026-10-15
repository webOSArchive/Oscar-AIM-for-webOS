<?php
/**
 * Checks auto_buddies against a running oscard with a new data directory:
 *
 *   OSCAR_LISTEN=127.0.0.1:5199 OSCAR_ADVERTISE=127.0.0.1:5199 OSCAR_DATA_DIR=<dir> php server/bin/oscard
 *
 * with alice, bob and carol in <dir>/accounts.json, each with the pass phrase "maple river lamp".
 * Run: php test/buddies.php 127.0.0.1:5199
 */
require_once __DIR__ . '/lib.php';

$addr = $argv[1] ?? '127.0.0.1:5199';

/** The stored buddy list (13,04 -> 13,06) as [name => [gid, bid, type, Tlvs]]. */
function feedbag(Client $c): array
{
    $c->sendSnac(0x13, 0x04, '', 50);
    while ($s = $c->snac()) if ($s[0] === 0x13 && $s[1] === 0x06) break;
    $r = new Reader($s[2] ?? '');
    $r->u8();
    $out = [];
    for ($n = $r->u16(); $n > 0; $n--) {
        $name = $r->str16();
        $gid = $r->u16(); $bid = $r->u16(); $type = $r->u16();
        $out[$type === 0 ? $name : "group $gid"] = [$gid, $bid, $type, $r->tlvsLen($r->u16())];
    }
    return $out;
}

/** What came to a session within a second: "13,08 bob", "03,0b bob", ... */
function drain(Client $c): array
{
    $got = [];
    while ($s = $c->snac(1)) {
        $r = new Reader($s[2]);
        $name = match ([$s[0], $s[1]]) {
            [0x13, 0x08], [0x13, 0x09] => $r->str16(),
            [0x03, 0x0B], [0x03, 0x0C] => $r->str8(),
            default => '',
        };
        $got[] = sprintf('%02x,%02x %s', $s[0], $s[1], $name);
    }
    return $got;
}

// 1. The first to sign on has nobody.
$alice = signIn($addr, 'alice');
drain($alice);
check(feedbag($alice) === [], "the first to sign on has an empty buddy list");

// 2. bob's first sign-on: he goes on alice's list, live, in a new group, and shows online.
$bob = signIn($addr, 'bob');
$got = drain($alice);
check(in_array('13,08 Buddies', $got) && in_array('13,08 bob', $got) && in_array('03,0b bob', $got),
    "alice is sent the new group, bob, and bob online: " . implode(', ', $got));
$fb = feedbag($alice);
check(isset($fb['bob']) && $fb['bob'][2] === 0, "bob is kept on alice's list");
$gid = $fb['bob'][0] ?? -1;
check(($fb["group $gid"][3] ?? new Tlvs())->get(0x00C8) === pack('n', $fb['bob'][1] ?? -1), "and in his group's member list");
check(($fb['group 0'][3] ?? new Tlvs())->get(0x00C8) === pack('n', $gid), "and the group in the root's");
drain($bob);
check(isset(feedbag($bob)['alice']), "alice is on bob's list");

// 3. carol: on both their lists, both on hers, in the group they already have.
$carol = signIn($addr, 'carol');
$fb = feedbag($alice);
check(isset($fb['bob'], $fb['carol']) && $fb['carol'][0] === $gid && count($fb) === 4, "alice has bob and carol in one group");
check(($fb["group $gid"][3] ?? new Tlvs())->get(0x00C8) === pack('nn', $fb['bob'][1], $fb['carol'][1]), "both in its member list");
drain($carol);
$fc = feedbag($carol);
check(isset($fc['alice'], $fc['bob']), "carol has alice and bob");

// 4. Only once: alice takes carol off, carol signs on again, and stays off.
drain($alice);
[$cg, $cb, $ct, $ctl] = $fb['carol'];
$alice->sendSnac(0x13, 0x0A, Wire::str16('carol') . pack('nnnn', $cg, $cb, $ct, 0), 60);
drain($alice);
fclose($carol->s);
drain($alice);
$carol = signIn($addr, 'carol');
$got = drain($alice);
check(!in_array('13,08 carol', $got) && !isset(feedbag($alice)['carol']), "a buddy taken off stays off at their next sign-on");

echo $fails ? "$fails failed\n" : "all passed\n";
exit($fails ? 1 : 0);
