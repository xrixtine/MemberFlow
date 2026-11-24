<?php

namespace App\Services;

/**
 * Removes bearer links (staff invitation tokens and signed applicant links)
 * from text that is about to be logged or stored.
 */
class SecureLinkRedactor
{
    public const PLACEHOLDER = '[secure link removed]';

    public static function redact(string $text, string $placeholder = self::PLACEHOLDER): string
    {
        $patterns = [
            // Absolute or relative invitation links: /invitations/{64-char token}
            '#(?:https?://[^\s"\'<>()]*)?/invitations/[A-Za-z0-9]{20,}#i',
            // Any URL (absolute or relative) carrying a signature parameter.
            '#(?:https?://[^\s"\'<>()]*|/[^\s"\'<>()]*)\?[^\s"\'<>()]*signature(?:=3D|=|%3D)[^\s"\'<>()]*#i',
        ];

        $text = (string) preg_replace($patterns, $placeholder, $text);

        // Bare signature values that survived (e.g. quoted-printable "=3D" or split lines).
        return (string) preg_replace('#(signature(?:=3D|=|%3D))[A-Fa-f0-9]{16,}#i', '$1[redacted]', $text);
    }
}
