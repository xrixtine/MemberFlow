<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public static function validInputs(): array
    {
        return [
            'whole number' => ['1500', 150000],
            'one decimal' => ['1500.5', 150050],
            'two decimals' => ['1500.50', 150050],
            'cents only' => ['0.01', 1],
            'ten cents' => ['0.1', 10],
            'zero' => ['0', 0],
            'surrounding spaces' => [' 25.75 ', 2575],
            'max digits' => ['99999999.99', 9999999999],
        ];
    }

    #[DataProvider('validInputs')]
    public function test_it_parses_valid_user_input_into_integer_cents(string $input, int $expected): void
    {
        $this->assertSame($expected, Money::fromInput($input));
    }

    public static function invalidInputs(): array
    {
        return [
            'negative' => ['-5'],
            'thousands separator' => ['1,000'],
            'three decimals' => ['10.005'],
            'letters' => ['abc'],
            'empty' => [''],
            'currency sign' => ['₱100'],
            'exponent' => ['1e3'],
            'trailing dot' => ['10.'],
            'too many digits' => ['123456789'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_it_rejects_invalid_user_input(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromInput($input);
    }

    public function test_it_converts_stored_decimal_strings(): void
    {
        $this->assertSame(125050, Money::fromDecimal('1250.50'));
        $this->assertSame(125050, Money::fromDecimal('1250.5'));
        $this->assertSame(0, Money::fromDecimal(null));
        $this->assertSame(0, Money::fromDecimal('0.00'));
        $this->assertSame(-1000, Money::fromDecimal('-10.00'));
        $this->assertSame(500, Money::fromDecimal('5.0000'));
    }

    public function test_it_formats_cents_for_storage_and_display(): void
    {
        $this->assertSame('1250.50', Money::toDecimal(125050));
        $this->assertSame('0.05', Money::toDecimal(5));
        $this->assertSame('0.00', Money::toDecimal(0));
        $this->assertSame('-10.00', Money::toDecimal(-1000));
        $this->assertSame('₱1,250.50', Money::format(125050));
        $this->assertSame('₱1,000,000.00', Money::format(100000000));
        $this->assertSame('₱0.30', Money::formatDecimal('0.30'));
    }

    public function test_integer_cent_arithmetic_avoids_floating_point_drift(): void
    {
        // 0.1 + 0.2 !== 0.3 with floats; with integer cents it is exact.
        $this->assertNotSame(0.3, 0.1 + 0.2);
        $sum = Money::fromInput('0.1') + Money::fromInput('0.2');
        $this->assertSame('0.30', Money::toDecimal($sum));

        // Many small partial payments add up exactly to the fee.
        $paid = 0;
        for ($i = 0; $i < 10; $i++) {
            $paid += Money::fromInput('33.33');
        }
        $paid += Money::fromInput('0.70');
        $this->assertSame(Money::fromInput('334.00'), $paid);
    }
}
