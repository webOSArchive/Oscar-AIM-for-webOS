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
 * and messages waiting for a user who was offline. It is files under data/, one per user.
 */

interface Accounts
{
    /**
     * Checks a BUCP sign-in. $hash is what the client sent in TLV 0x25; $md5Pass says the
     * client hashed MD5(password) (TLV 0x4C present) rather than the password itself.
     * Returns the screen name as it should be displayed, or null.
     */
    public function verify(string $sn, string $key, string $hash, bool $md5Pass): ?string;
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
        $row = $this->find(Wire::normalize($sn));
        if (!$row || !$row['chat_key']) return null;
        // The Museum's username rule (AccountRepository::usernameError), checked here too:
        // an email or a leading digit is never a screen name, whatever the row holds.
        if (!preg_match('/^[A-Za-z][A-Za-z0-9._-]{2,31}$/', $row['username'])) return null;
        return self::phraseMatches($key, $hash, $md5Pass, $row['chat_key']) ? $row['username'] : null;
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
}

final class UserData
{
    public function __construct(private string $dir)
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

    public function queueOffline(string $to, array $msg): void
    {
        $f = $this->file('offline', $to);
        $all = is_file($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
        $all[] = $msg;
        $this->write($f, $all);
    }

    public function takeOffline(string $sn): array
    {
        $f = $this->file('offline', $sn);
        if (!is_file($f)) return [];
        $all = json_decode(file_get_contents($f), true) ?: [];
        unlink($f);
        return $all;
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
