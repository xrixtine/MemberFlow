<?php

namespace App\Exceptions;

use DomainException;

/**
 * A business-rule violation in the membership domain (e.g. deleting a member
 * that has transactions). Controllers turn it into a friendly flash message.
 */
class MembershipException extends DomainException {}
