<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Closure;

/**
 * A short-lived, single-use numeric verification code held in memory by one process.
 *
 *  - Generated with random_int() (a cryptographically secure generator).
 *  - Only an HMAC-SHA256 of the code is kept, keyed with 32 random bytes that are
 *    unique to this challenge. The plaintext is returned once by issue() so it can be
 *    emailed, and is never stored here.
 *  - Expires after the given number of minutes, allows a limited number of incorrect
 *    attempts, and works only once: after success, expiry or the last incorrect
 *    attempt the hash is erased, so every later attempt fails.
 *  - Comparison uses hash_equals() (constant time).
 */
final class OneTimeCode
{
    public const DIGITS = 8;

    public const VALID = 'valid';

    public const INCORRECT = 'incorrect';

    public const EXPIRED = 'expired';

    public const EXHAUSTED = 'exhausted';

    public const USED = 'used';

    private ?string $hash;

    private ?string $key;

    private int $attemptsLeft;

    private bool $verified = false;

    private function __construct(string $hash, string $key, private readonly CarbonImmutable $expiresAt, int $maxAttempts)
    {
        $this->hash = $hash;
        $this->key = $key;
        $this->attemptsLeft = max(1, $maxAttempts);
    }

    /**
     * Create a challenge.
     *
     * @param  (Closure(): string)|null  $generator  test seam only; production uses generate()
     * @return array{0: self, 1: string} the challenge and the plaintext code (send it, then discard it)
     */
    public static function issue(int $ttlMinutes, int $maxAttempts = 3, ?Closure $generator = null): array
    {
        $code = $generator !== null ? (string) $generator() : self::generate();
        $key = random_bytes(32);

        $challenge = new self(
            hash_hmac('sha256', $code, $key),
            $key,
            CarbonImmutable::now()->addMinutes(max(0, $ttlMinutes)),
            $maxAttempts,
        );

        return [$challenge, $code];
    }

    /** An 8-digit code from the operating system's CSPRNG (leading zeros kept). */
    public static function generate(): string
    {
        return str_pad((string) random_int(0, 10 ** self::DIGITS - 1), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** Check an entered code. Spaces and dashes are ignored (e.g. "1234 5678"). */
    public function verify(?string $input): string
    {
        if ($this->verified || $this->hash === null) {
            return self::USED;
        }

        if ($this->isExpired()) {
            $this->erase();

            return self::EXPIRED;
        }

        $input = preg_replace('/[\s-]+/', '', (string) $input);

        if ($input !== '' && hash_equals($this->hash, hash_hmac('sha256', $input, (string) $this->key))) {
            $this->verified = true;
            $this->erase();

            return self::VALID;
        }

        $this->attemptsLeft--;
        if ($this->attemptsLeft <= 0) {
            $this->erase();

            return self::EXHAUSTED;
        }

        return self::INCORRECT;
    }

    public function isExpired(): bool
    {
        return ! CarbonImmutable::now()->lessThan($this->expiresAt);
    }

    public function isVerified(): bool
    {
        return $this->verified;
    }

    public function attemptsLeft(): int
    {
        return $this->hash === null ? 0 : $this->attemptsLeft;
    }

    public function expiresAt(): CarbonImmutable
    {
        return $this->expiresAt;
    }

    private function erase(): void
    {
        $this->hash = null;
        $this->key = null;
    }

    /** Never reveal the hash or key in dumps, logs or exception traces. */
    public function __debugInfo(): array
    {
        return ['expires_at' => $this->expiresAt->toIso8601String(), 'attempts_left' => $this->attemptsLeft(), 'verified' => $this->verified];
    }

    public function __serialize(): array
    {
        throw new \LogicException('A one-time code cannot be serialized.');
    }
}
