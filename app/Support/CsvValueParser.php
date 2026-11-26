<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\DomainException;
use DateTimeImmutable;

final class CsvValueParser
{
    public static function normalizeDate(mixed $value, ?string $today = null): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new DomainException('La fecha CSV no es válida.');
        }
        $value = trim($value);
        foreach (['!Y-m-d', '!d/m/Y', '!d-m-Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                if ($date->format(substr($format, 1)) === $value && $date->format('Y-m-d') <= ($today ?? date('Y-m-d'))) {
                    return $date->format('Y-m-d');
                }
                break;
            }
        }

        throw new DomainException('La fecha CSV debe ser válida y no futura (AAAA-MM-DD o DD/MM/AAAA).');
    }

    public static function normalizeAmount(mixed $value): string
    {
        if ((!is_string($value) && !is_int($value)) || !mb_check_encoding((string) $value, 'UTF-8')) {
            throw new DomainException('El importe CSV no es válido.');
        }
        $amount = trim((string) $value);
        $amount = preg_replace('/(?:COP|\$|\s|\x{00A0})/iu', '', $amount);
        if (!is_string($amount) || $amount === '' || preg_match('/[^0-9.,]/', $amount)) {
            throw new DomainException('El importe CSV no es válido.');
        }
        $lastComma = strrpos($amount, ',');
        $lastDot = strrpos($amount, '.');
        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                if (!preg_match('/\A(?:\d+|\d{1,3}(?:\.\d{3})+),\d{1,2}\z/', $amount)) {
                    throw new DomainException('El importe CSV tiene separadores decimales inconsistentes.');
                }
                $amount = str_replace('.', '', $amount);
                $amount = str_replace(',', '.', $amount);
            } else {
                if (!preg_match('/\A(?:\d+|\d{1,3}(?:,\d{3})+)\.\d{1,2}\z/', $amount)) {
                    throw new DomainException('El importe CSV tiene separadores decimales inconsistentes.');
                }
                $amount = str_replace(',', '', $amount);
            }
        } elseif ($lastComma !== false) {
            $decimalDigits = strlen($amount) - $lastComma - 1;
            if ($decimalDigits > 0 && $decimalDigits <= 2) {
                $amount = str_replace(',', '.', $amount);
            } elseif (preg_match('/\A\d{1,3}(?:,\d{3})+\z/', $amount)) {
                $amount = str_replace(',', '', $amount);
            } else {
                throw new DomainException('El importe CSV tiene separadores decimales inconsistentes.');
            }
        } elseif ($lastDot !== false) {
            $decimalDigits = strlen($amount) - $lastDot - 1;
            if ($decimalDigits > 0 && $decimalDigits <= 2) {
                if (substr_count($amount, '.') > 1) {
                    throw new DomainException('El importe CSV tiene separadores decimales inconsistentes.');
                }
            } elseif (preg_match('/\A\d{1,3}(?:\.\d{3})+\z/', $amount)) {
                $amount = str_replace('.', '', $amount);
            } else {
                throw new DomainException('El importe CSV tiene separadores decimales inconsistentes.');
            }
        }

        try {
            return DecimalAmount::normalize($amount);
        } catch (DomainException $exception) {
            throw new DomainException('El importe CSV debe ser positivo y tener hasta dos decimales.', 0, $exception);
        }
    }
}
