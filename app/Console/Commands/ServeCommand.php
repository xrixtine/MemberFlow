<?php

namespace App\Console\Commands;

use App\Services\SecureLinkRedactor;
use Illuminate\Foundation\Console\ServeCommand as BaseServeCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `php artisan serve` with secure links removed from its request log.
 *
 * PHP's built-in development server prints every request path. Without this,
 * invitation tokens (/invitations/{token}) and signed applicant links
 * (?expires=…&signature=…) would appear in the terminal. This replaces them with
 * "[secure link removed]" before the output is parsed and printed.
 *
 * It only covers `php artisan serve`. Other web servers (Apache, Nginx) keep their
 * own access logs — see docs/email-and-security.md for how to exclude these routes.
 */
#[AsCommand(name: 'serve')]
class ServeCommand extends BaseServeCommand
{
    protected function handleProcessOutput()
    {
        return function ($type, $buffer) {
            $this->outputBuffer .= $buffer;

            // Redact complete lines only, so a link split across two chunks is still caught.
            $cut = strrpos($this->outputBuffer, "\n");
            if ($cut !== false) {
                $this->outputBuffer = SecureLinkRedactor::redact(substr($this->outputBuffer, 0, $cut + 1))
                    .substr($this->outputBuffer, $cut + 1);
            }

            $this->flushOutputBuffer();
        };
    }
}
