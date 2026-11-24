<?php

namespace App\Exceptions;

use DomainException;

/**
 * Invitation / user-account business-rule violation. Controllers show the message.
 */
class AccountException extends DomainException
{
    public function __construct(string $message, public readonly string $field = 'email')
    {
        parent::__construct($message);
    }
}
