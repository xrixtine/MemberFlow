<?php

namespace Tests\Unit;

use App\Support\NumberGenerator;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class NumberGeneratorTest extends TestCase
{
    public function test_it_builds_readable_numbers_from_the_primary_key(): void
    {
        $date = new DateTimeImmutable('2026-11-11');

        $this->assertSame('MF-2026-00042', NumberGenerator::membership(42, $date));
        $this->assertSame('TXN-20261111-000042', NumberGenerator::transaction(42, $date));
        $this->assertSame('OR-2026-000042', NumberGenerator::receipt(42, $date));
    }

    public function test_numbers_stay_unique_beyond_the_padding_width(): void
    {
        $date = new DateTimeImmutable('2026-01-01');

        $this->assertSame('MF-2026-123456', NumberGenerator::membership(123456, $date));
        $this->assertNotSame(NumberGenerator::membership(1, $date), NumberGenerator::membership(10, $date));
    }
}
