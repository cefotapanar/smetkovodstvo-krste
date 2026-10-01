<?php

namespace App\Support;

/**
 * Пари во СТОТИНКИ (int) за секое сметање.
 *
 * Зошто не float: збир од илјадници ставки во float излегува со стотинка
 * разлика, а налог што „не се затвора за 0,01“ е налог што не смее да се
 * прокнижи. Базата враќа decimal како текст ("12390.50"), SQLite понекогаш
 * како број — `cents()` ги прима двата и не минува низ float кога има текст.
 */
final class Money
{
    public static function cents(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_int($value)) {
            return $value * 100;
        }

        $s = trim((string) $value);

        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,}))?$/', $s, $m)) {
            $frac = substr(($m[3] ?? '').'000', 0, 3);
            // Трета децимала → заокружување половина нагоре (од нулата).
            $cents = (int) $m[2] * 100 + (int) substr($frac, 0, 2) + ((int) $frac[2] >= 5 ? 1 : 0);

            return $m[1] === '-' ? -$cents : $cents;
        }

        // Научен запис и слично (SQLite SUM) — ретко, затоа преку float.
        return (int) round((float) $s * 100);
    }

    /** За JSON — број со најмногу 2 децимали. */
    public static function out(int $cents): float
    {
        return $cents / 100;
    }

    /** За запис во decimal колона — текст, без float. */
    public static function db(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
