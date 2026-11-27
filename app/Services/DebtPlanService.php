<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DomainException;
use App\Support\DebtPayoffCalculator;
use App\Support\DecimalAmount;
use DateTimeImmutable;

final class DebtPlanService
{
    public function __construct(
        private readonly LiabilityService $liabilities,
        private readonly FinancialSpaceService $spaces,
    ) {
    }

    public function plan(int $userId, mixed $spaceKey, mixed $payments): array
    {
        if (!is_array($payments)) {
            throw new DomainException('Los pagos mensuales enviados no son válidos.');
        }
        $space = $this->spaces->resolve($userId, $spaceKey);
        $liabilities = array_values(array_filter(
            $this->liabilities->listForUser($userId, true),
            static fn (array $liability): bool => $liability['scope'] === $space['scope']
                && ($liability['owner_user_id'] === null ? null : (int) $liability['owner_user_id']) === $space['owner_user_id']
                && ($liability['household_id'] === null ? null : (int) $liability['household_id']) === $space['household_id']
        ));
        $plans = [];
        foreach ($liabilities as $liability) {
            $paymentValue = $payments[(string) $liability['id']] ?? '';
            $plan = null;
            if ($paymentValue !== '' && $paymentValue !== null && (string) $liability['current_balance'] !== '0.00') {
                $payment = DecimalAmount::normalize($paymentValue);
                $rate = $liability['annual_interest_rate'] === null
                    ? '0.000'
                    : (string) $liability['annual_interest_rate'];
                $plan = DebtPayoffCalculator::calculate(
                    (string) $liability['current_balance'],
                    $rate,
                    $payment
                );
            }
            $liability['payment_input'] = is_string($paymentValue) || is_int($paymentValue)
                ? (string) $paymentValue
                : '';
            $liability['plan'] = (string) $liability['current_balance'] === '0.00'
                ? ['status' => 'paid', 'months' => 0, 'total_interest' => '0.00']
                : $plan;
            $liability['next_due_date'] = $liability['kind'] === 'credit_card'
                ? self::nextDueDate((int) $liability['payment_due_day'])
                : null;
            $plans[] = $liability;
        }

        return [
            'space' => $space,
            'plans' => $plans,
        ];
    }

    private static function nextDueDate(int $day): string
    {
        if ($day < 1 || $day > 28) {
            throw new DomainException('Se encontró un día de pago de tarjeta no válido.');
        }
        $today = new DateTimeImmutable('today');
        $due = $today->setDate((int) $today->format('Y'), (int) $today->format('n'), $day);
        if ($due < $today) {
            $nextMonth = $today->modify('first day of next month');
            $due = $nextMonth->setDate(
                (int) $nextMonth->format('Y'),
                (int) $nextMonth->format('n'),
                $day
            );
        }

        return $due->format('Y-m-d');
    }
}
