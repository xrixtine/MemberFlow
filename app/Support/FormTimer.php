<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Minimum form-completion time for public forms (anti-bot).
 *
 * The form carries an ENCRYPTED issue timestamp, so it cannot be forged or edited.
 * A submission faster than config('memberflow.apply_min_seconds') is rejected,
 * and so is a token older than config('memberflow.apply_form_max_minutes').
 */
class FormTimer
{
    public static function issue(?int $timestamp = null): string
    {
        return Crypt::encryptString('apply|'.($timestamp ?? time()));
    }

    /** Null when acceptable, otherwise a user-facing error message. */
    public static function problem(mixed $token): ?string
    {
        $issuedAt = null;

        if (is_string($token) && $token !== '') {
            try {
                $plain = Crypt::decryptString($token);
                if (preg_match('/^apply\|(\d{9,11})$/', $plain, $m)) {
                    $issuedAt = (int) $m[1];
                }
            } catch (DecryptException) {
                $issuedAt = null;
            }
        }

        if ($issuedAt === null) {
            return 'The form could not be verified. Please reload the page and submit again.';
        }

        $elapsed = time() - $issuedAt;

        if ($elapsed < (int) config('memberflow.apply_min_seconds', 3)) {
            return 'That was quicker than expected. Please review your details and submit again.';
        }
        if ($elapsed > (int) config('memberflow.apply_form_max_minutes', 120) * 60) {
            return 'This form has expired. Please review your details and submit again.';
        }

        return null;
    }
}
