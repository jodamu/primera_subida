<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\DomainException;
use App\Support\CsvValueParser;
use PHPUnit\Framework\TestCase;

final class CsvValueParserTest extends TestCase
{
    public function testNormalizesCommonDecimalAndThousandsSeparators(): void
    {
        self::assertSame('1234.56', CsvValueParser::normalizeAmount('1.234,56'));
        self::assertSame('1234.56', CsvValueParser::normalizeAmount('1,234.56'));
        self::assertSame('1234.56', CsvValueParser::normalizeAmount('1234,56'));
        self::assertSame('1234.00', CsvValueParser::normalizeAmount('1.234'));
    }

    public function testNormalizesSupportedDateFormatsAndRejectsFutureDates(): void
    {
        self::assertSame('2025-03-04', CsvValueParser::normalizeDate('04/03/2025', '2025-03-31'));
        self::assertSame('2025-03-04', CsvValueParser::normalizeDate('2025-03-04', '2025-03-31'));

        $this->expectException(DomainException::class);
        CsvValueParser::normalizeDate('2025-04-01', '2025-03-31');
    }

    public function testRejectsAmountsWithExcessPrecision(): void
    {
        $this->expectException(DomainException::class);
        CsvValueParser::normalizeAmount('12.3456');
    }

    public function testDoesNotMistakeInvalidDecimalPrecisionForThousandsSeparators(): void
    {
        $this->expectException(DomainException::class);
        CsvValueParser::normalizeAmount('12,3456');
    }
}
