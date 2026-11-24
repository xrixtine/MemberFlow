<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum TransactionType: string
{
    use HasOptions;

    case Application = 'application';
    case Renewal = 'renewal';

    public function label(): string
    {
        return match ($this) {
            self::Application => 'New application',
            self::Renewal => 'Renewal',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Application => 'blue',
            self::Renewal => 'lime',
        };
    }
}
