<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\DomainException;
use DateTimeImmutable;

final class FinancialPeriod
{
    public static function containing(int $startDay, DateTimeImmutable $date): array
    {
        if ($startDay < 1 || $startDay > 28) {
            throw new DomainException('El inicio del periodo debe estar entre 1 y 28.');
        }

        $start = $date->setDate((int) $date->format('Y'), (int) $date->format('n'), $startDay);
        if ((int) $date->format('j') < $startDay) {
            $start = $start->modify('-1 month');
        }

        return [
            $start->format('Y-m-d'),
            $start->modify('+1 month')->modify('-1 day')->format('Y-m-d'),
        ];
    }
}
