<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\DomainException;

final class DebtPayoffCalculator
{
    public static function calculate(string $balance, string $annualRate, string $monthlyPayment): array
    {
        $balanceCents = self::cents($balance);
        $paymentCents = self::cents($monthlyPayment);
        $rateThousandths = self::rateThousandths($annualRate);
        if ($rateThousandths > 100_000) {
            throw new DomainException('La tasa anual registrada supera el máximo permitido.');
        }
        if ($balanceCents <= 0 || $paymentCents <= 0) {
            throw new DomainException('El saldo y el pago mensual deben ser mayores que cero.');
        }

        $remainingCents = $balanceCents;
        $interestCents = 0;
        for ($month = 1; $month <= 600; $month++) {
            $interestDenominator = 1_200_000;
            $monthlyInterest = intdiv($remainingCents, $interestDenominator) * $rateThousandths
                + intdiv(
                    ($remainingCents % $interestDenominator) * $rateThousandths + intdiv($interestDenominator, 2),
                    $interestDenominator
                );
            if ($paymentCents <= $monthlyInterest) {
                return [
                    'status' => 'insufficient_payment',
                    'months' => null,
                    'total_interest' => null,
                ];
            }

            $interestCents += $monthlyInterest;
            $remainingCents += $monthlyInterest - $paymentCents;
            if ($remainingCents <= 0) {
                if ($interestCents > 999_999_999_999_999) {
                    return [
                        'status' => 'interest_limit',
                        'months' => null,
                        'total_interest' => null,
                    ];
                }
                return [
                    'status' => 'payoff',
                    'months' => $month,
                    'total_interest' => self::decimal($interestCents),
                ];
            }
        }

        return [
            'status' => 'beyond_horizon',
            'months' => null,
            'total_interest' => null,
        ];
    }

    private static function cents(string $amount): int
    {
        $normalized = DecimalAmount::normalize($amount, true);

        return (int) str_replace('.', '', $normalized);
    }

    private static function rateThousandths(string $rate): int
    {
        if (!preg_match('/\A\d{1,3}(?:\.\d{1,3})?\z/', $rate)) {
            throw new DomainException('La tasa anual registrada no es válida.');
        }

        [$whole, $fraction] = array_pad(explode('.', $rate, 2), 2, '');

        return ((int) $whole * 1000) + (int) str_pad($fraction, 3, '0');
    }

    private static function decimal(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
