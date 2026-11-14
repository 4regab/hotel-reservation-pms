<?php
declare(strict_types=1);

namespace Lib;

/** CSV reading and writing with spreadsheet-formula protection on output. */
final class Csv
{
    /** @return list<list<string>> rows, header included. Handles quotes, CRLF and a UTF-8 BOM. */
    public static function parse(string $text): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $rows = [];
        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $text);
        rewind($fh);
        while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if ($r === [null]) {
                continue;
            }
            $rows[] = array_map(static fn ($c) => trim((string) $c), $r);
        }
        fclose($fh);
        return $rows;
    }

    /** Neutralise cells a spreadsheet would run as formulas (=, +, -, @, tab, CR) unless they are plain numbers. */
    public static function safeCell(string|int|float|null $v): string
    {
        $s = (string) $v;
        if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false && !is_numeric($s)) {
            return "'" . $s;
        }
        return $s;
    }

    /** @param list<list<string|int|float|null>> $rows */
    public static function write(array $rows): string
    {
        $fh = fopen('php://memory', 'r+');
        foreach ($rows as $r) {
            fputcsv($fh, array_map([self::class, 'safeCell'], $r), ',', '"', '', "\n");
        }
        rewind($fh);
        return (string) stream_get_contents($fh);
    }
}
