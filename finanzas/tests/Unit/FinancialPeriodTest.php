<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\FinancialPeriod;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class FinancialPeriodTest extends TestCase
{
    public function testSelectsThePeriodContainingTheGivenDate(): void
    {
        self::assertSame(
            ['2025-01-05', '2025-02-04'],
            FinancialPeriod::containing(5, new DateTimeImmutable('2025-02-01'))
        );
        self::assertSame(
            ['2025-02-05', '2025-03-04'],
            FinancialPeriod::containing(5, new DateTimeImmutable('2025-02-05'))
        );
    }

    public function testSupportsTheMaximumConfiguredStartDayAcrossShortMonths(): void
    {
        self::assertSame(
            ['2025-02-28', '2025-03-27'],
            FinancialPeriod::containing(28, new DateTimeImmutable('2025-03-01'))
        );
    }

}
