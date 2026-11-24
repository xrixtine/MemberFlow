<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Accepted payment methods. Requests with any other value are rejected
 * by validation (Rule::enum) before reaching PaymentService.
 */
enum PaymentMethod: string
{
    use HasOptions;

    case Cash = 'cash';
    case GCash = 'gcash';
    case BankTransfer = 'bank_transfer';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::GCash => 'GCash',
            self::BankTransfer => 'Bank transfer',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Cash => 'green',
            self::GCash => 'blue',
            self::BankTransfer => 'gray',
        };
    }

    /** Hint shown next to the optional external reference field. */
    public function referenceHint(): string
    {
        return match ($this) {
            self::Cash => 'Optional for cash.',
            self::GCash => 'GCash reference number from the sender\'s confirmation.',
            self::BankTransfer => 'Bank transaction or deposit slip number.',
        };
    }
}
