<?php

namespace Tests\Unit;

use App\Support\OneTimeCode;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class OneTimeCodeTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_generated_codes_are_eight_digits_and_vary(): void
    {
        $codes = [];
        for ($i = 0; $i < 200; $i++) {
            $code = OneTimeCode::generate();
            $this->assertMatchesRegularExpression('/^\d{8}$/', $code);
            $codes[$code] = true;
        }
        // 200 draws from 10^8 values: a repeat would point at a broken generator.
        $this->assertGreaterThan(195, count($codes));
    }

    public function test_the_plaintext_code_is_never_kept_in_the_challenge(): void
    {
        [$challenge, $code] = OneTimeCode::issue(15);

        $reflection = new \ReflectionObject($challenge);
        foreach ($reflection->getProperties() as $property) {
            $value = $property->getValue($challenge);
            if (is_string($value)) {
                $this->assertStringNotContainsString($code, $value, "property {$property->getName()} holds the plaintext code");
            }
        }
        $this->assertStringNotContainsString($code, print_r($challenge, true));
        $this->assertStringNotContainsString($code, var_export($challenge->__debugInfo(), true));
    }

    public function test_a_challenge_cannot_be_serialized(): void
    {
        [$challenge] = OneTimeCode::issue(15);

        $this->expectException(\LogicException::class);
        serialize($challenge);
    }

    public function test_the_correct_code_works_exactly_once(): void
    {
        [$challenge, $code] = OneTimeCode::issue(15);

        $this->assertSame(OneTimeCode::VALID, $challenge->verify($code));
        $this->assertTrue($challenge->isVerified());
        $this->assertSame(OneTimeCode::USED, $challenge->verify($code));
    }

    public function test_spaces_and_dashes_in_the_entered_code_are_ignored(): void
    {
        [$challenge] = OneTimeCode::issue(15, 3, fn () => '12345678');

        $this->assertSame(OneTimeCode::VALID, $challenge->verify(' 1234-5678 '));
    }

    public function test_three_incorrect_attempts_destroy_the_code(): void
    {
        [$challenge, $code] = OneTimeCode::issue(15, 3, fn () => '12345678');

        $this->assertSame(OneTimeCode::INCORRECT, $challenge->verify('00000000'));
        $this->assertSame(2, $challenge->attemptsLeft());
        $this->assertSame(OneTimeCode::INCORRECT, $challenge->verify(''));
        $this->assertSame(1, $challenge->attemptsLeft());
        $this->assertSame(OneTimeCode::EXHAUSTED, $challenge->verify(null));
        $this->assertSame(0, $challenge->attemptsLeft());

        // Even the right code is refused afterwards.
        $this->assertSame(OneTimeCode::USED, $challenge->verify($code));
        $this->assertFalse($challenge->isVerified());
    }

    public function test_the_code_expires_after_fifteen_minutes(): void
    {
        CarbonImmutable::setTestNow('2026-11-24 10:00:00');
        [$challenge, $code] = OneTimeCode::issue(15);

        CarbonImmutable::setTestNow('2026-11-24 10:14:59');
        $this->assertFalse($challenge->isExpired());

        CarbonImmutable::setTestNow('2026-11-24 10:15:00');
        $this->assertTrue($challenge->isExpired());
        $this->assertSame(OneTimeCode::EXPIRED, $challenge->verify($code));
        $this->assertSame(OneTimeCode::USED, $challenge->verify($code));
    }

    public function test_a_code_entered_just_in_time_is_accepted(): void
    {
        CarbonImmutable::setTestNow('2026-11-24 10:00:00');
        [$challenge, $code] = OneTimeCode::issue(15);

        CarbonImmutable::setTestNow('2026-11-24 10:14:59');
        $this->assertSame(OneTimeCode::VALID, $challenge->verify($code));
    }

    public function test_each_challenge_uses_its_own_key(): void
    {
        [$a] = OneTimeCode::issue(15, 3, fn () => '11112222');
        [$b] = OneTimeCode::issue(15, 3, fn () => '11112222');

        $hash = fn (OneTimeCode $c) => (new \ReflectionProperty($c, 'hash'))->getValue($c);
        $this->assertNotSame($hash($a), $hash($b));
        $this->assertSame(64, strlen($hash($a)));
    }
}
