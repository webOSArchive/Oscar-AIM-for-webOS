<?php
/**
 * Where accounts and per-user state live.
 *
 * Accounts answers one question: is this sign-in right, and what is the screen name's
 * display form. FileAccounts reads a JSON file (development and test accounts);
 * PdoAccounts reads the App Museum's `accounts` table, where the screen name is the
 * webOS Account's username and the password its webOS Chat pass phrase (`chat_key`).
 * ChainAccounts asks several stores in turn, so test accounts can stay in the file.
 *
 * UserData keeps what the server holds for each screen name: the buddy list (feedbag),
 * and messages waiting for a user who was offline. It is files under data/, one per user,
 * and roster.json, everyone who has signed on (for auto_buddies).
 */

interface Accounts
{
    /**
     * Checks a BUCP sign-in. $hash is what the client sent in TLV 0x25; $md5Pass says the
     * client hashed MD5(password) (TLV 0x4C present) rather than the password itself.
     * Returns the screen name as it should be displayed, or null.
     */
    public function verify(string $sn, string $key, string $hash, bool $md5Pass): ?string;

    /** Whether $sn is an account that can sign in (offline messages are kept only for those). */
    public function exists(string $sn): bool;
}

/** Shared by both account stores: the two BUCP hashes. */
trait BucpCheck
{
    private static string $MAGIC = 'AOL Instant Messenger (SM)';

    /** $passMd5 is MD5(password), raw. $plain is the password, when it is known. */
    private static function bucpMatches(string $key, string $hash, bool $md5Pass, string $passMd5, ?string $plain): bool
    {
        if ($md5Pass) {
            return hash_equals(md5($key . $passMd5 . self::$MAGIC, true), $hash);
        }
        return $plain !== null && hash_equals(md5($key . $plain . self::$MAGIC, true), $hash);
    }

    /**
     * Checks a sign-in against a pass phrase the server knows in full. A pass phrase is
     * three lowercase words ("maple river lamp"), typed on small keyboards that capitalize
     * on their own, so the first letter, or each word's, may be capital, and the spaces
     * may be left out. The client hashes what was typed, so each form is tried.
     */
    private static function phraseMatches(string $key, string $hash, bool $md5Pass, string $phrase): bool
    {
        $forms = [];
        foreach ([$phrase, ucfirst($phrase), ucwords($phrase)] as $f) {
            $forms[$f] = true;
            $forms[str_replace(' ', '', $f)] = true;
        }
        foreach (array_keys($forms) as $f) {
            if (self::bucpMatches($key, $hash, $md5Pass, md5((string)$f, true), (string)$f)) return true;
        }
        return false;
    }
}

/**
 * data/accounts.json: {"codepoet": {"screenName": "codepoet", "chatKey": "..."}}, keyed by
 * the normalized name. For development; production reads the Museum's database.
 */
final class FileAccounts implements Accounts
{
    use BucpCheck;

    public function __construct(private string $path) {}

    public function verify(string $sn, string $key, string $hash, bool $md5Pass): ?string
    {
        $all = json_decode(@file_get_contents($this->path) ?: '{}', true) ?: [];
        $a = $all[Wire::normalize($sn)] ?? null;
        if (!$a || !isset($a['chatKey'])) return null;
        return self::phraseMatches($key, $hash, $md5Pass, $a['chatKey']) ? ($a['screenName'] ?? $sn) : null;
    }

    public function exists(string $sn): bool
    {
        $all = json_decode(@file_get_contents($this->path) ?: '{}', true) ?: [];
        return isset($all[Wire::normalize($sn)]['chatKey']);
    }
}

/**
 * The App Museum's accounts: `username` and `chat_key` (the webOS Chat pass phrase, stored
 * as is; see webos-catalog-service's migration 0012) on `accounts`, active accounts only.
 * Usernames have no spaces and the column's collation ignores case, so the normalized
 * screen name finds the row directly. A username that is still an email or starts with a
 * digit never gets a pass phrase on the Museum side, so it can't sign in here.
 */
final class PdoAccounts implements Accounts
{
    use BucpCheck;

    private ?PDO $db = null;

    public function __construct(private string $dsn, private string $user, private string $pass) {}

    public function verify(string $sn, string $key, string $hash, bool $md5Pass): ?string
    {
        $row = $this->usable($sn);
        return $row && self::phraseMatches($key, $hash, $md5Pass, $row['chat_key']) ? $row['username'] : null;
    }

    public function exists(string $sn): bool
    {
        return $this->usable($sn) !== null;
    }

    /** The row for $sn if it can sign in: a pass phrase, and the Museum's username rule. */
    private function usable(string $sn): ?array
    {
        $row = $this->find(Wire::normalize($sn));
        if (!$row || !$row['chat_key']) return null;
        // The Museum's username rule (AccountRepository::usernameError), checked here too:
        // an email or a leading digit is never a screen name, whatever the row holds.
        return preg_match('/^[A-Za-z][A-Za-z0-9._-]{2,31}$/', $row['username']) ? $row : null;
    }

    /** One retry on a new connection: MySQL drops an idle one (wait_timeout) under a long-running daemon. */
    private function find(string $name): ?array
    {
        for ($try = 0; ; $try++) {
            try {
                $this->db ??= new PDO($this->dsn, $this->user, $this->pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $q = $this->db->prepare(
                    "SELECT username, chat_key FROM accounts WHERE username = ? AND status = 'active' LIMIT 1");
                $q->execute([$name]);
                return $q->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (PDOException $e) {
                $this->db = null;
                if ($try > 0) {
                    error_log("account lookup failed: " . $e->getMessage());
                    return null;
                }
            }
        }
    }
}

/** Asks each store in turn; the first that accepts the sign-in wins. */
final class ChainAccounts implements Accounts
{
    /** @param Accounts[] $stores */
    public function __construct(private array $stores) {}

    public function verify(string $sn, string $key, string $hash, bool $md5Pass): ?string
    {
        foreach ($this->stores as $s) {
            if (($name = $s->verify($sn, $key, $hash, $md5Pass)) !== null) return $name;
        }
        return null;
    }

    public function exists(string $sn): bool
    {
        foreach ($this->stores as $s) if ($s->exists($sn)) return true;
        return false;
    }
}

final class UserData
{
    /**
     * $offlineMax messages are kept for each user, each for $offlineDays days, so what one
     * sender can make the server store is bounded.
     */
    public function __construct(private string $dir, private int $offlineMax = 100, private int $offlineDays = 30)
    {
        @mkdir("$dir/feedbag", 0700, true);
        @mkdir("$dir/offline", 0700, true);
    }

    /** The buddy list: items of {name, gid, bid, type, tlvs (hex)}. */
    public function feedbag(string $sn): array
    {
        $f = $this->file('feedbag', $sn);
        return is_file($f) ? (json_decode(file_get_contents($f), true) ?: ['items' => [], 'stamp' => 0]) : ['items' => [], 'stamp' => 0];
    }

    public function saveFeedbag(string $sn, array $fb): void
    {
        $fb['stamp'] = time();
        $this->write($this->file('feedbag', $sn), $fb);
    }

    /** Screen names (normalized) that $sn has as buddies. */
    public function buddiesOf(string $sn): array
    {
        $out = [];
        foreach ($this->feedbag($sn)['items'] as $it) {
            if ($it['type'] === 0x0000 && $it['name'] !== '') $out[Wire::normalize($it['name'])] = true;
        }
        return array_keys($out);
    }

    /** Everyone who has signed on since the roster was started: normalized name => display form. */
    public function roster(): array
    {
        $f = "{$this->dir}/roster.json";
        return is_file($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
    }

    /** Adds $sn to the roster. Returns everyone already in it when $sn is new, null when it isn't. */
    public function joinRoster(string $sn): ?array
    {
        $all = $this->roster();
        $n = Wire::normalize($sn);
        if (isset($all[$n])) return null;
        $others = $all;
        $all[$n] = $sn;
        $this->write("{$this->dir}/roster.json", $all);
        return $others;
    }

    /**
     * Puts $buddy on $sn's buddy list, unless it is already there: in the group "Buddies", or
     * the first group if there is no such one, or a new "Buddies". Returns the edits for $sn's
     * sessions, as [subtype, item] in the order to send them: adds (13,08), then the groups
     * whose member lists (TLV 0xC8) changed (13,09).
     */
    public function addBuddy(string $sn, string $buddy): array
    {
        $fb = $this->feedbag($sn);
        $items = $fb['items'];
        $nb = Wire::normalize($buddy);
        $root = $group = null;
        $gids = $bids = [];
        foreach ($items as $i => $it) {
            if ($it['type'] === 0x0000 && Wire::normalize($it['name']) === $nb) return [];
            $gids[$it['gid']] = $bids[$it['bid']] = true;
            if ($it['type'] !== 0x0001 || $it['bid'] !== 0) continue;
            if ($it['gid'] === 0) $root = $i;
            elseif ($group === null || ($it['name'] === self::GROUP && $items[$group]['name'] !== self::GROUP)) $group = $i;
        }
        $adds = $changed = [];
        if ($root === null) {
            $items[] = ['name' => '', 'gid' => 0, 'bid' => 0, 'type' => 0x0001, 'tlvs' => ''];
            $root = array_key_last($items);
            $adds[] = $root;
        }
        if ($group === null) {
            for ($gid = 1; isset($gids[$gid]); $gid++);
            $items[] = ['name' => self::GROUP, 'gid' => $gid, 'bid' => 0, 'type' => 0x0001, 'tlvs' => ''];
            $group = array_key_last($items);
            $adds[] = $group;
            $items[$root]['tlvs'] = self::withMember($items[$root]['tlvs'], $gid);
            $changed[] = $root;
        }
        for ($bid = 1; isset($bids[$bid]); $bid++);
        $gid = $items[$group]['gid'];
        $items[] = ['name' => $buddy, 'gid' => $gid, 'bid' => $bid, 'type' => 0x0000, 'tlvs' => ''];
        $adds[] = array_key_last($items);
        $items[$group]['tlvs'] = self::withMember($items[$group]['tlvs'], $bid);
        array_unshift($changed, $group);

        $fb['items'] = $items;
        $this->saveFeedbag($sn, $fb);
        $edits = [];
        foreach ($adds as $i) $edits[] = [0x08, $items[$i]];
        foreach ($changed as $i) if (!in_array($i, $adds, true)) $edits[] = [0x09, $items[$i]];
        return $edits;
    }

    private const GROUP = 'Buddies';

    /** A group's TLVs (hex) with $id added to its member list, TLV 0xC8. */
    private static function withMember(string $tlvs, int $id): string
    {
        $out = new Tlvs();
        $members = '';
        foreach ((new Reader(hex2bin($tlvs)))->tlvs()->list as [$type, $v]) {
            if ($type === 0x00C8) $members = $v;
            else $out->add($type, $v);
        }
        return bin2hex($out->add(0x00C8, $members . pack('n', $id))->encode());
    }

    /** Keeps a message for $to; false when $to already has as many as are kept. */
    public function queueOffline(string $to, array $msg): bool
    {
        $f = $this->file('offline', $to);
        $all = $this->fresh(is_file($f) ? (json_decode(file_get_contents($f), true) ?: []) : []);
        if (count($all) >= $this->offlineMax) return false;
        $all[] = $msg;
        $this->write($f, $all);
        return true;
    }

    public function takeOffline(string $sn): array
    {
        $f = $this->file('offline', $sn);
        if (!is_file($f)) return [];
        $all = json_decode(file_get_contents($f), true) ?: [];
        unlink($f);
        return $this->fresh($all);
    }

    /** Drops expired messages from every queue; returns how many went. */
    public function sweepOffline(): int
    {
        $gone = 0;
        foreach (glob("{$this->dir}/offline/*.json") ?: [] as $f) {
            $all = json_decode((string)@file_get_contents($f), true) ?: [];
            $kept = $this->fresh($all);
            $gone += count($all) - count($kept);
            if (!$kept) @unlink($f);
            elseif (count($kept) !== count($all)) $this->write($f, $kept);
        }
        return $gone;
    }

    private function fresh(array $msgs): array
    {
        $since = time() - $this->offlineDays * 86400;
        return array_values(array_filter($msgs, fn($m) => ($m['time'] ?? 0) >= $since));
    }

    private function file(string $kind, string $sn): string
    {
        return "{$this->dir}/$kind/" . preg_replace('/[^a-z0-9@._-]/', '_', Wire::normalize($sn)) . '.json';
    }

    private function write(string $f, array $data): void
    {
        file_put_contents("$f.tmp", json_encode($data, JSON_PRETTY_PRINT));
        rename("$f.tmp", $f);
    }
}
