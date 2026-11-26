<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\DomainException;
use DateTimeImmutable;

final class RecurringSchedule
{
    public static function nextDate(
        string $currentDate,
        string $frequency,
        int $interval,
        int $anchorDay
    ): string {
        $date = DateValue::normalize($currentDate, $currentDate);
        if ($interval < 1 || $interval > 12 || $anchorDay < 1 || $anchorDay > 31) {
            throw new DomainException('La programación recurrente no es válida.');
        }

        $current = new DateTimeImmutable($date);
        if ($frequency === 'weekly') {
            return $current->modify('+' . $interval . ' weeks')->format('Y-m-d');
        }

        if ($frequency === 'monthly') {
            $monthIndex = (int) $current->format('n') - 1 + $interval;
            $year = (int) $current->format('Y') + intdiv($monthIndex, 12);
            $month = ($monthIndex % 12) + 1;
            $lastDay = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');

            return sprintf('%04d-%02d-%02d', $year, $month, min($anchorDay, $lastDay));
        }

        if ($frequency === 'yearly') {
            $year = (int) $current->format('Y') + $interval;
            $month = (int) $current->format('n');
            $lastDay = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');

            return sprintf('%04d-%02d-%02d', $year, $month, min($anchorDay, $lastDay));
        }

        throw new DomainException('Selecciona una frecuencia válida.');
    }
}
