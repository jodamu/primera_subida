<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\DomainException;
use App\Support\DecimalAmount;
use PHPUnit\Framework\TestCase;

final class DecimalAmountTest extends TestCase
{
    public function testNormalizesAndFormatsDecimalStringsWithoutFloatConversion(): void
    {
        self::assertSame('123.40', DecimalAmount::normalize('000123.4'));
        self::assertSame('$ 1.234.567,80', DecimalAmount::format('1234567.80'));
    }

    public function testZeroIsAllowedOnlyWhenRequested(): void
    {
        self::assertSame('0.00', DecimalAmount::normalize('0', true));

        $this->expectException(DomainException::class);
        DecimalAmount::normalize('0');
    }

    public function testRejectsFloatingPointValues(): void
    {
        $this->expectException(DomainException::class);
        DecimalAmount::normalize(1.25);
    }

    public function testRejectsMoreThanTwoDecimalPlaces(): void
    {
        $this->expectException(DomainException::class);
        DecimalAmount::normalize('1.234');
    }

    public function testAcceptsMaximumDatabaseAmountAndRejectsOverflow(): void
    {
        self::assertSame('9999999999999.99', DecimalAmount::normalize('9999999999999.99'));

        $this->expectException(DomainException::class);
        DecimalAmount::normalize('10000000000000.00');
    }
}
