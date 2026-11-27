<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\DomainException;
use App\Support\DebtPayoffCalculator;
use PHPUnit\Framework\TestCase;

final class DebtPayoffCalculatorTest extends TestCase
{
    public function testCalculatesZeroInterestPayoffWithoutFloatingPointAmounts(): void
    {
        self::assertSame(
            [
                'status' => 'payoff',
                'months' => 4,
                'total_interest' => '0.00',
            ],
            DebtPayoffCalculator::calculate('100.00', '0.000', '30.00')
        );
    }

    public function testRejectsPaymentThatOnlyCoversMonthlyInterest(): void
    {
        self::assertSame(
            [
                'status' => 'insufficient_payment',
                'months' => null,
                'total_interest' => null,
            ],
            DebtPayoffCalculator::calculate('1000.00', '12.000', '10.00')
        );
    }

    public function testRejectsAnInvalidAnnualRate(): void
    {
        $this->expectException(DomainException::class);
        DebtPayoffCalculator::calculate('1000.00', '120.001', '100.00');
    }
}
