<?php

declare(strict_types=1);

namespace EKabineti\Core;

/** Exact comparison of decimal(19,4) amounts as strings (no float rounding, no bcmath dependency). */
final class Money
{
    public static function compare(string|int|float|null $a, string|int|float|null $b): int
    {
        [$signA, $intA, $fracA] = self::parts($a);
        [$signB, $intB, $fracB] = self::parts($b);
        if ($intA === '0' && $fracA === '0000') $signA = 1;
        if ($intB === '0' && $fracB === '0000') $signB = 1;
        if ($signA !== $signB) return $signA <=> $signB;
        $magnitude = strlen($intA) <=> strlen($intB) ?: strcmp($intA, $intB) <=> 0 ?: strcmp($fracA, $fracB) <=> 0;
        return $signA * $magnitude;
    }

    /** @return array{0:int,1:string,2:string} */
    private static function parts(string|int|float|null $value): array
    {
        $value = is_float($value) ? number_format($value, 4, '.', '') : trim((string) ($value ?? '0'));
        if ($value === '') $value = '0';
        $sign = 1;
        if ($value[0] === '-' || $value[0] === '+') { $sign = $value[0] === '-' ? -1 : 1; $value = substr($value, 1); }
        if (!preg_match('/^\d*(?:\.\d*)?$/', $value)) throw new \InvalidArgumentException('Invalid decimal amount.');
        [$int, $frac] = array_pad(explode('.', $value, 2), 2, '');
        $int = ltrim($int, '0');
        $frac = substr(str_pad($frac, 4, '0'), 0, 4);
        return [$sign, $int === '' ? '0' : $int, $frac];
    }
}
