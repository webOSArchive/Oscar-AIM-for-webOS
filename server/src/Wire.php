<?php
/**
 * OSCAR's wire formats: big-endian integers, TLVs, SNAC headers and FLAP frames.
 *
 * Reader walks a binary string; the static writers build one. Nothing here knows about
 * sessions or families.
 */

final class Reader
{
    private int $pos = 0;

    public function __construct(private string $buf) {}

    public function left(): int { return strlen($this->buf) - $this->pos; }

    public function u8(): int  { $this->need(1); return ord($this->buf[$this->pos++]); }
    public function u16(): int { $this->need(2); $v = unpack('n', $this->buf, $this->pos)[1]; $this->pos += 2; return $v; }
    public function u32(): int { $this->need(4); $v = unpack('N', $this->buf, $this->pos)[1]; $this->pos += 4; return $v; }

    public function raw(int $n): string
    {
        $this->need($n);
        $s = substr($this->buf, $this->pos, $n);
        $this->pos += $n;
        return $s;
    }

    public function rest(): string { return $this->raw($this->left()); }

    /** A string led by its one-byte length (screen names). */
    public function str8(): string { return $this->raw($this->u8()); }

    /** A string led by its two-byte length. */
    public function str16(): string { return $this->raw($this->u16()); }

    /** TLVs until the end of the buffer, as [type => [value, ...]] in order. */
    public function tlvs(): Tlvs
    {
        $t = new Tlvs();
        while ($this->left() >= 4) {
            $type = $this->u16();
            $t->add($type, $this->raw($this->u16()));
        }
        return $t;
    }

    /** A counted block of TLVs (a u16 count first). */
    public function tlvsCounted(): Tlvs
    {
        $t = new Tlvs();
        for ($n = $this->u16(); $n > 0; $n--) {
            $type = $this->u16();
            $t->add($type, $this->raw($this->u16()));
        }
        return $t;
    }

    /** A block of TLVs that runs for $len bytes. */
    public function tlvsLen(int $len): Tlvs
    {
        return (new Reader($this->raw($len)))->tlvs();
    }

    private function need(int $n): void
    {
        if ($this->left() < $n) {
            throw new UnderflowException("short read: wanted $n, have {$this->left()}");
        }
    }
}

final class Tlvs
{
    /** @var array<int, array{int, string}> type and value, in order */
    public array $list = [];

    public function add(int $type, string $value): self { $this->list[] = [$type, $value]; return $this; }
    public function u8(int $type, int $v): self  { return $this->add($type, chr($v)); }
    public function u16(int $type, int $v): self { return $this->add($type, pack('n', $v)); }
    public function u32(int $type, int $v): self { return $this->add($type, pack('N', $v)); }

    public function has(int $type): bool { return $this->get($type) !== null; }

    public function get(int $type): ?string
    {
        foreach ($this->list as [$t, $v]) {
            if ($t === $type) return $v;
        }
        return null;
    }

    public function getU16(int $type): ?int
    {
        $v = $this->get($type);
        return $v !== null && strlen($v) >= 2 ? unpack('n', $v)[1] : null;
    }

    public function count(): int { return count($this->list); }

    public function encode(): string
    {
        $out = '';
        foreach ($this->list as [$t, $v]) {
            $out .= pack('nn', $t, strlen($v)) . $v;
        }
        return $out;
    }

    /** With a u16 count first, as user info blocks carry them. */
    public function encodeCounted(): string { return pack('n', $this->count()) . $this->encode(); }
}

final class Wire
{
    public const CH_SIGNON = 1;
    public const CH_SNAC = 2;
    public const CH_ERROR = 3;
    public const CH_SIGNOFF = 4;
    public const CH_KEEPALIVE = 5;

    public static function flap(int $channel, int $seq, string $data): string
    {
        return '*' . chr($channel) . pack('nn', $seq & 0xffff, strlen($data)) . $data;
    }

    public static function snac(int $family, int $subtype, int $flags, int $reqid, string $body): string
    {
        return pack('nnnN', $family, $subtype, $flags, $reqid) . $body;
    }

    public static function str8(string $s): string { return chr(strlen($s)) . $s; }
    public static function str16(string $s): string { return pack('n', strlen($s)) . $s; }

    /** Screen names compare without case or spaces, as AIM's did. */
    public static function normalize(string $sn): string
    {
        return strtolower(str_replace(' ', '', $sn));
    }

    public static function hex(string $bin): string { return bin2hex($bin); }
}
