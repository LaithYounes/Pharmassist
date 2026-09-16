<?php

namespace App\Services;

use InvalidArgumentException;

final class Money
{
    // Monetary input has at most two fractional digits. All arithmetic uses cents.
    public static function cents(string|int $amount): int
    {
        $value = (string) $amount;
        if (!preg_match('/^(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,2}))?$/D', $value, $match)) {
            throw new InvalidArgumentException('Money must be a nonnegative decimal with at most two places.');
        }
        return (int) $match[1] * 100 + (int) str_pad($match[2] ?? '', 2, '0');
    }

    public static function decimal(int $cents): string
    {
        if ($cents === PHP_INT_MIN) throw new InvalidArgumentException('Money value is out of range.');
        return ($cents < 0 ? '-' : '').intdiv(abs($cents), 100).'.'.str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function add(int $left, int $right): int
    {
        if (($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)) {
            throw new InvalidArgumentException('Money sum is out of range.');
        }
        return $left + $right;
    }

    public static function multiply(int $unitCents, int $quantity): int
    {
        if ($unitCents < 0 || $quantity < 0
            || ($unitCents > 0 && $quantity > intdiv(PHP_INT_MAX, $unitCents))) {
            throw new InvalidArgumentException('Money product is out of range.');
        }
        return $unitCents * $quantity;
    }
}
