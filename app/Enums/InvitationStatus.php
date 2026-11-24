<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum InvitationStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Accepted = 'accepted';
    case Revoked = 'revoked';
    case Expired = 'expired';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Accepted => 'green',
            self::Revoked => 'red',
            self::Expired => 'gray',
        };
    }
}
