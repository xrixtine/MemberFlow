<?php

namespace App\Support;

use DateTimeInterface;

/**
 * Human-readable official numbers derived from the row's primary key.
 *
 * The row is inserted first (inside a DB transaction) and the number is then
 * built from its auto-increment id, so two requests can never receive the
 * same number. A UNIQUE index on each column backs this up.
 *
 * These numbers are for display/printing only. Public URLs never use them.
 */
final class NumberGenerator
{
    /** MF-2026-00042 */
    public static function membership(int $id, DateTimeInterface $date): string
    {
        return sprintf('MF-%s-%05d', $date->format('Y'), $id);
    }

    /** TXN-20261111-000042 */
    public static function transaction(int $id, DateTimeInterface $date): string
    {
        return sprintf('TXN-%s-%06d', $date->format('Ymd'), $id);
    }

    /** OR-2026-000042 (official receipt) */
    public static function receipt(int $id, DateTimeInterface $date): string
    {
        return sprintf('OR-%s-%06d', $date->format('Y'), $id);
    }
}
