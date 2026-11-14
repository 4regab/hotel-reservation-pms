<?php
declare(strict_types=1);

namespace Portal\Web;

/**
 * Small server-rendered SVG charts (no script, no library, no CDN). Every chart has a title and description for screen
 * readers, and the page also offers the numbers as a table. Colors are chosen to pass 3:1 against white.
 */
final class Charts
{
    /**
     * Vertical bars. @param list<array{label:string,value:float,muted?:bool,note?:string}> $bars
     * @param callable(float):string $fmt axis and tooltip formatter
     */
    public static function bars(string $title, string $desc, array $bars, callable $fmt, ?float $max = null, string $color = '#3b5f8f', string $mutedColor = '#a0bbdb'): string
    {
        $w = 720;
        $h = 240;
        $l = 52;
        $longest = 0;
        for ($i = 0; $i <= 4; $i++) {
            $longest = max($longest, strlen($fmt(self::niceMax($max ?? max(1.0, ...array_map(fn ($x) => (float) $x['value'], $bars ?: [['value' => 1]]))) * $i / 4)));
        }
        $l = max(44, $longest * 7 + 16);
        $r = 8;
        $t = 12;
        $b = 34;
        $n = max(1, count($bars));
        $max ??= max(1.0, ...array_map(fn ($x) => (float) $x['value'], $bars ?: [['value' => 1]]));
        $max = self::niceMax($max);
        $pw = $w - $l - $r;
        $ph = $h - $t - $b;
        $slot = $pw / $n;
        $bw = max(1.5, $slot * 0.72);
        $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" class="h-auto w-full" role="img" aria-labelledby="c-' . md5($title) . '-t c-' . md5($title) . '-d" xmlns="http://www.w3.org/2000/svg">'
            . '<title id="c-' . md5($title) . '-t">' . e($title) . '</title><desc id="c-' . md5($title) . '-d">' . e($desc) . '</desc>';
        for ($i = 0; $i <= 4; $i++) {
            $y = $t + $ph - ($ph * $i / 4);
            $svg .= '<line x1="' . $l . '" x2="' . ($w - $r) . '" y1="' . round($y, 1) . '" y2="' . round($y, 1) . '" stroke="#e6e8ef" stroke-width="1"/>'
                . '<text x="' . ($l - 6) . '" y="' . round($y + 4, 1) . '" text-anchor="end" font-size="12" fill="#454f6e">' . e($fmt($max * $i / 4)) . '</text>';
        }
        $every = max(1, (int) ceil($n / 9));
        foreach ($bars as $i => $bar) {
            $bh = $max > 0 ? $ph * min(1, (float) $bar['value'] / $max) : 0;
            $x = $l + $slot * $i + ($slot - $bw) / 2;
            $y = $t + $ph - $bh;
            $svg .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($bw, 1) . '" height="' . round($bh, 1) . '" rx="2" fill="' . (!empty($bar['muted']) ? $mutedColor : $color) . '"><title>' . e($bar['label'] . ': ' . $fmt((float) $bar['value']) . (isset($bar['note']) ? ' (' . $bar['note'] . ')' : '')) . '</title></rect>';
            if ($i % $every === 0) {
                $svg .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . ($h - 14) . '" text-anchor="middle" font-size="12" fill="#454f6e">' . e(self::shortLabel($bar['label'])) . '</text>';
            }
        }
        return $svg . '</svg>';
    }

    /** Horizontal bars with the value written at the end. @param list<array{label:string,value:float}> $rows */
    public static function hbars(string $title, string $desc, array $rows, callable $fmt, string $color = '#3b5f8f'): string
    {
        $w = 720;
        $rowH = 34;
        $h = max(60, count($rows) * $rowH + 8);
        $l = 150;
        $max = max(1.0, ...array_map(fn ($x) => (float) $x['value'], $rows ?: [['value' => 1]]));
        $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" class="h-auto w-full" role="img" aria-labelledby="h-' . md5($title) . '-t h-' . md5($title) . '-d" xmlns="http://www.w3.org/2000/svg"><title id="h-' . md5($title) . '-t">' . e($title) . '</title><desc id="h-' . md5($title) . '-d">' . e($desc) . '</desc>';
        foreach ($rows as $i => $row) {
            $y = 4 + $i * $rowH;
            $bw = ($w - $l - 110) * ((float) $row['value'] / $max);
            $svg .= '<text x="' . ($l - 8) . '" y="' . ($y + 19) . '" text-anchor="end" font-size="13" fill="#283556">' . e($row['label']) . '</text>'
                . '<rect x="' . $l . '" y="' . ($y + 4) . '" width="' . round(max(2, $bw), 1) . '" height="22" rx="3" fill="' . $color . '"/>'
                . '<text x="' . round($l + max(2, $bw) + 8, 1) . '" y="' . ($y + 20) . '" font-size="13" font-weight="600" fill="#1f2b4d">' . e($fmt((float) $row['value'])) . '</text>';
        }
        return $svg . '</svg>';
    }

    private static function niceMax(float $v): float
    {
        if ($v <= 1.0001 && $v > 0.2) {
            return 1.0;
        }
        $pow = 10 ** floor(log10(max($v, 1e-9)));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($v <= $m * $pow) {
                return $m * $pow;
            }
        }
        return 10 * $pow;
    }

    private static function shortLabel(string $label): string
    {
        return preg_match('/^\d{4}-(\d{2})-(\d{2})$/', $label, $m) ? (int) $m[2] . '/' . (int) $m[1] : $label;
    }
}
