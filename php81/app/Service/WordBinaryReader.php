<?php

declare(strict_types=1);

namespace EKabineti\Service;

/**
 * Text of a Word 97–2003 document (.doc) without LibreOffice: the OLE compound file is read, then the piece table of
 * the WordDocument stream gives the text of the main document (8-bit Windows-1252 or UTF-16 pieces). Paragraph and
 * table-cell marks become paragraph breaks; field codes keep only their result. Formatting is not read.
 */
final class WordBinaryReader
{
    private const SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    private const END_OF_CHAIN = 0xFFFFFFFE;

    private string $data;
    private int $sectorSize;
    private int $miniSectorSize;
    private int $miniCutoff;
    /** @var list<int> */
    private array $fat = [];
    /** @var list<int> */
    private array $miniFat = [];
    /** @var array<string,array{start:int,size:int,type:int}> */
    private array $entries = [];
    private string $miniStream = '';

    /** @return list<string> paragraphs of the main document */
    public static function paragraphs(string $path): array
    {
        $data = file_get_contents($path);
        if ($data === false) throw new \RuntimeException('Документот не може да се прочита.');
        $reader = new self($data);
        $text = $reader->text();
        $paragraphs = [];
        foreach (preg_split('/[\r\x07\x0c]+/u', $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/[\x00-\x08\x0b\x0e-\x1f]+/u', ' ', str_replace("\x0b", ' ', $line)));
            $line = trim((string) preg_replace('/\s+/u', ' ', $line));
            if ($line !== '') $paragraphs[] = $line;
        }
        return $paragraphs;
    }

    private function __construct(string $data)
    {
        if (strlen($data) < 512 || substr($data, 0, 8) !== self::SIGNATURE) throw new \RuntimeException('Датотеката не е Word 97–2003 документ (.doc).');
        $this->data = $data;
        $this->sectorSize = 1 << self::u16($data, 0x1E);
        $this->miniSectorSize = 1 << self::u16($data, 0x20);
        $this->miniCutoff = self::u32($data, 0x38);
        $fatSectors = [];
        for ($i = 0; $i < 109; $i++) { $s = self::u32($data, 0x4C + $i * 4); if ($s < 0xFFFFFFFA) $fatSectors[] = $s; }
        $difat = self::u32($data, 0x44);
        $guard = 0;
        while ($difat < 0xFFFFFFFA && $guard++ < 10000) {
            $sector = $this->sector($difat);
            $per = intdiv($this->sectorSize, 4) - 1;
            for ($i = 0; $i < $per; $i++) { $s = self::u32($sector, $i * 4); if ($s < 0xFFFFFFFA) $fatSectors[] = $s; }
            $difat = self::u32($sector, $per * 4);
        }
        foreach ($fatSectors as $s) { $sector = $this->sector($s); for ($i = 0; $i < $this->sectorSize; $i += 4) $this->fat[] = self::u32($sector, $i); }
        $directory = $this->chain(self::u32($data, 0x30));
        for ($offset = 0; $offset + 128 <= strlen($directory); $offset += 128) {
            $nameLength = self::u16($directory, $offset + 64);
            $type = ord($directory[$offset + 66]);
            if ($type === 0 || $nameLength < 2) continue;
            $name = (string) mb_convert_encoding(substr($directory, $offset, $nameLength - 2), 'UTF-8', 'UTF-16LE');
            $this->entries[$name] ??= ['start'=>self::u32($directory, $offset + 116), 'size'=>self::u32($directory, $offset + 120), 'type'=>$type];
            if ($type === 5) $this->miniStream = $this->chain(self::u32($directory, $offset + 116), self::u32($directory, $offset + 120));
        }
        $miniFatStart = self::u32($data, 0x3C);
        if ($miniFatStart < 0xFFFFFFFA) { $miniFat = $this->chain($miniFatStart); for ($i = 0; $i + 4 <= strlen($miniFat); $i += 4) $this->miniFat[] = self::u32($miniFat, $i); }
    }

    private function text(): string
    {
        $word = $this->stream('WordDocument');
        if (strlen($word) < 0x1AA || self::u16($word, 0) !== 0xA5EC) throw new \RuntimeException('Word документот ја нема очекуваната структура.');
        if (self::u16($word, 0x0A) & 0x0004) throw new \RuntimeException('Документот е зачуван со „fast save“; зачувајте го повторно или како .docx.');
        if (self::u16($word, 0x0A) & 0x0100) throw new \RuntimeException('Документот е заштитен со лозинка.');
        $table = $this->stream((self::u16($word, 0x0A) & 0x0200) ? '1Table' : '0Table');
        $ccpText = self::u32($word, 0x4C);
        $fcClx = self::u32($word, 0x1A2);
        $lcbClx = self::u32($word, 0x1A6);
        $clx = substr($table, $fcClx, $lcbClx);
        $pos = 0;
        while ($pos < strlen($clx) && ord($clx[$pos]) === 0x01) $pos += 3 + self::u16($clx, $pos + 1);
        if ($pos >= strlen($clx) || ord($clx[$pos]) !== 0x02) throw new \RuntimeException('Табелата со текст на Word документот не е пронајдена.');
        $plc = substr($clx, $pos + 5, self::u32($clx, $pos + 1));
        $pieces = intdiv(strlen($plc) - 4, 12);
        $text = '';
        for ($i = 0; $i < $pieces; $i++) {
            $cpStart = self::u32($plc, $i * 4);
            $cpEnd = min(self::u32($plc, ($i + 1) * 4), $ccpText);
            if ($cpStart >= $cpEnd) continue;
            $fc = self::u32($plc, ($pieces + 1) * 4 + $i * 8 + 2);
            $count = $cpEnd - $cpStart;
            if ($fc & 0x40000000) $text .= (string) mb_convert_encoding(substr($word, ($fc & 0x3FFFFFFF) >> 1, $count), 'UTF-8', 'Windows-1252');
            else $text .= (string) mb_convert_encoding(substr($word, $fc, $count * 2), 'UTF-8', 'UTF-16LE');
        }
        // Fields: \x13 instruction \x14 result \x15 → result (nested fields included).
        do { $before = $text; $text = (string) preg_replace('/\x13[^\x13\x14\x15]*\x14([^\x13\x15]*)\x15/u', '$1', $text); $text = (string) preg_replace('/\x13[^\x13\x14\x15]*\x15/u', '', $text); } while ($text !== $before);
        return str_replace(["\x13", "\x14", "\x15"], '', $text);
    }

    private function stream(string $name): string
    {
        $entry = $this->entries[$name] ?? null;
        if ($entry === null) throw new \RuntimeException('Word документот го нема делот '.$name.'.');
        if ($entry['size'] < $this->miniCutoff) {
            $out = ''; $sector = $entry['start']; $guard = 0;
            while ($sector < 0xFFFFFFFA && strlen($out) < $entry['size'] && $guard++ < 1000000) {
                $out .= substr($this->miniStream, $sector * $this->miniSectorSize, $this->miniSectorSize);
                $sector = $this->miniFat[$sector] ?? self::END_OF_CHAIN;
            }
            return substr($out, 0, $entry['size']);
        }
        return $this->chain($entry['start'], $entry['size']);
    }

    private function chain(int $start, ?int $size = null): string
    {
        $out = ''; $sector = $start; $guard = 0;
        while ($sector < 0xFFFFFFFA && ($size === null || strlen($out) < $size) && $guard++ < 1000000) {
            $out .= $this->sector($sector);
            $sector = $this->fat[$sector] ?? self::END_OF_CHAIN;
        }
        return $size === null ? $out : substr($out, 0, $size);
    }

    private function sector(int $index): string
    {
        return (string) substr($this->data, ($index + 1) * $this->sectorSize, $this->sectorSize);
    }

    private static function u16(string $s, int $o): int { return strlen($s) >= $o + 2 ? unpack('v', $s, $o)[1] : 0; }
    private static function u32(string $s, int $o): int { return strlen($s) >= $o + 4 ? unpack('V', $s, $o)[1] : 0; }
}
