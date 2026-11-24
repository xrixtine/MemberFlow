<?php

namespace App\Exceptions;

use DomainException;

/**
 * A task business-rule violation (e.g. assigning an inactive user,
 * updating a completed task). Controllers show the message to the user.
 */
class TaskException extends DomainException
{
    public function __construct(string $message, public readonly string $field = 'task')
    {
        parent::__construct($message);
    }
}
