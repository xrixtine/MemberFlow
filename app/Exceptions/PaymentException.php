<?php

namespace App\Exceptions;

use DomainException;

/**
 * A payment business-rule violation (overpayment, payment on a closed
 * transaction, voiding after approval, ...). Controllers show the message.
 */
class PaymentException extends DomainException
{
    public function __construct(string $message, public readonly string $field = 'amount')
    {
        parent::__construct($message);
    }
}
