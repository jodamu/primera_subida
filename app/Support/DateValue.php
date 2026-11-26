<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\DomainException;
use DateTimeImmutable;

final class DateValue
{
    public static function normalize(mixed $value, string $fallback): string
    {
        if ($value === null || $value === '') {
            $value = $fallback;
        } elseif (!is_string($value)) {
            throw new DomainException('La fecha no es válida.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if (
            !$date
            || $date->format('Y-m-d') !== $value
            || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new DomainException('La fecha no es válida.');
        }

        return $date->format('Y-m-d');
    }
}
