<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\DomainException;
use App\Support\DateValue;
use PHPUnit\Framework\TestCase;

final class DateValueTest extends TestCase
{
    public function testUsesFallbackForAnEmptyDate(): void
    {
        self::assertSame('2025-01-31', DateValue::normalize('', '2025-01-31'));
    }

    public function testRejectsAnImpossibleCalendarDate(): void
    {
        $this->expectException(DomainException::class);
        DateValue::normalize('2025-02-29', '2025-01-01');
    }
}
