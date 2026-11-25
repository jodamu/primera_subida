<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\RecurringSchedule;
use PHPUnit\Framework\TestCase;

final class RecurringScheduleTest extends TestCase
{
    public function testMonthlyScheduleKeepsItsAnchorDayAfterAShortMonth(): void
    {
        self::assertSame(
            '2025-02-28',
            RecurringSchedule::nextDate('2025-01-31', 'monthly', 1, 31)
        );
        self::assertSame(
            '2025-03-31',
            RecurringSchedule::nextDate('2025-02-28', 'monthly', 1, 31)
        );
    }

    public function testYearlyScheduleClampsLeapDayToFebruaryEnd(): void
    {
        self::assertSame(
            '2025-02-28',
            RecurringSchedule::nextDate('2024-02-29', 'yearly', 1, 29)
        );
    }
}
