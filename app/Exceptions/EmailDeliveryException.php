<?php

namespace App\Exceptions;

use RuntimeException;

/** A delivery action (e.g. a manual retry) is not allowed. The message is user-facing. */
class EmailDeliveryException extends RuntimeException {}
