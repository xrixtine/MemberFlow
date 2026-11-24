<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum MemberSource: string
{
    use HasOptions;

    case WalkIn = 'walk_in';
    case Online = 'online';

    public function label(): string
    {
        return match ($this) {
            self::WalkIn => 'Walk-in',
            self::Online => 'Online',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::WalkIn => 'gray',
            self::Online => 'blue',
        };
    }
}
