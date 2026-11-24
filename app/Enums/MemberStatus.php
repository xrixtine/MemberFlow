<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum MemberStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Active = 'active';
    case Expired = 'expired';
    case Inactive = 'inactive';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Active => 'green',
            self::Expired => 'red',
            self::Inactive => 'gray',
        };
    }
}
