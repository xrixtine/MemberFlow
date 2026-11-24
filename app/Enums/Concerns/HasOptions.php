<?php

namespace App\Enums\Concerns;

/**
 * Shared helpers for backed enums that are shown in forms and badges.
 */
trait HasOptions
{
    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<string, string> value => label, for <select> options */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
