<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\DomainException;

final class DecimalAmount
{
    public static function normalize(mixed $value, bool $allowZero = false): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw new DomainException('El valor debe ser un importe decimal válido.');
        }

        $value = (string) $value;
        if (!preg_match('/\A\d{1,13}(?:\.\d{1,2})?\z/', $value)) {
            throw new DomainException('El importe debe tener hasta 13 dígitos enteros y 2 decimales.');
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad($fraction, 2, '0');

        if (!$allowZero && $whole === '0' && $fraction === '00') {
            throw new DomainException('El importe debe ser mayor que cero.');
        }

        return $whole . '.' . $fraction;
    }

    public static function format(string $amount): string
    {
        $negative = str_starts_with($amount, '-');
        $absolute = $negative ? substr($amount, 1) : $amount;
        $normalized = self::normalize($absolute, true);
        [$whole, $fraction] = explode('.', $normalized, 2);
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $whole);

        return ($negative ? '-$ ' : '$ ') . $grouped . ',' . $fraction;
    }
}
