<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Integer-cent money helpers.
 *
 * Amounts are stored in MySQL as DECIMAL(10,2). Eloquent's "decimal:2" cast
 * returns them as strings (e.g. "1250.50"), so we parse strings directly
 * into integer cents and never pass money through PHP floats.
 */
final class Money
{
    /** Pattern accepted from user input: up to 8 whole digits, optional 1–2 decimals. */
    public const INPUT_PATTERN = '/^\d{1,8}(\.\d{1,2})?$/';

    /**
     * Parse a value typed by a user (e.g. "1500", "1500.5", "0.75") into cents.
     *
     * @throws InvalidArgumentException for negatives, separators, >2 decimals, etc.
     */
    public static function fromInput(string $input): int
    {
        $input = trim($input);

        if (! preg_match(self::INPUT_PATTERN, $input)) {
            throw new InvalidArgumentException('Enter an amount like 1500 or 1500.50 (no commas, at most 2 decimals).');
        }

        return self::parse($input);
    }

    /**
     * Convert a stored decimal value ("1250.50", "0", null) into cents.
     */
    public static function fromDecimal(string|int|null $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        $value = trim((string) $value);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException("Invalid decimal amount [{$value}].");
        }

        $negative = str_starts_with($value, '-');
        $cents = self::parse(ltrim($value, '-'));

        return $negative ? -$cents : $cents;
    }

    /** Convert cents back into a DECIMAL(10,2) string for storage ("1250.50"). */
    public static function toDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Human-readable peso amount, e.g. "₱1,250.50". */
    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign.'₱'.number_format(intdiv($abs, 100)).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Convenience: format a stored decimal string directly. */
    public static function formatDecimal(string|int|null $value): string
    {
        return self::format(self::fromDecimal($value));
    }

    /** Parse an unsigned "123" / "123.4" / "123.45" / "123.456" (extra digits must be zero). */
    private static function parse(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        $fraction = rtrim($fraction, '0') === '' ? '' : $fraction;
        if (strlen(rtrim($fraction, '0')) > 2) {
            throw new InvalidArgumentException("Amount [{$value}] has more than 2 decimal places.");
        }

        $fraction = substr(str_pad($fraction, 2, '0'), 0, 2);

        return ((int) $whole) * 100 + (int) $fraction;
    }
}
