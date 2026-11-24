<?php

namespace App\Logging;

use App\Services\SecureLinkRedactor;
use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * Log "tap": removes invitation tokens and signed-link signatures from every
 * application log record (message and string context values) before it is written.
 * Defence in depth — application code never logs these links on purpose.
 */
class RedactSecureLinks
{
    public function __invoke(Logger $logger): void
    {
        $logger->getLogger()->pushProcessor(function (LogRecord $record): LogRecord {
            $context = $record->context;
            array_walk_recursive($context, function (&$value) {
                if (is_string($value)) {
                    $value = SecureLinkRedactor::redact($value);
                }
            });

            return $record->with(message: SecureLinkRedactor::redact($record->message), context: $context);
        });
    }
}
