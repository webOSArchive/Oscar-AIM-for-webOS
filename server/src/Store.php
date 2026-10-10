<?php
/**
 * Where accounts and per-user state live.
 *
 * Accounts answers one question: is this sign-in right, and what is the screen name's
 * display form. FileAccounts reads a JSON file (development); PdoAccounts reads the App
 * Museum's `accounts` table (production, see the plan).
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
        $ok = self::bucpMatches($key, $hash, $md5Pass, md5($a['chatKey'], true), $a['chatKey']);
        return $ok ? ($a['screenName'] ?? $sn) : null;
    }
}

/**
 * The App Museum's accounts: `username` and `chat_key_md5` (hex MD5 of the chat key) on
 * `accounts`, active accounts only. Old clients that hash the plain password (no TLV 0x4C)
 * can't be checked against an MD5, so they are refused; every libpurple since 2.x sends 0x4C.
 */
final class PdoAccounts implements Accounts
{
    use BucpCheck;

    private ?PDO $db = null;

    public function __construct(private string $dsn, private string $user, private string $pass) {}

    public function verify(string $sn, string $key, string $hash, bool $md5Pass): ?string
    {
        $this->db ??= new PDO($this->dsn, $this->user, $this->pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $q = $this->db->prepare(
            "SELECT username, chat_key_md5 FROM accounts
             WHERE LOWER(REPLACE(username, ' ', '')) = ? AND status = 'active' LIMIT 1");
        $q->execute([Wire::normalize($sn)]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row || !$row['chat_key_md5']) return null;
        $ok = self::bucpMatches($key, $hash, $md5Pass, hex2bin($row['chat_key_md5']), null);
        return $ok ? $row['username'] : null;
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
