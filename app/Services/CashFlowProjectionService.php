<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DomainException;
use App\Repositories\RecurringMovementRepository;
use App\Support\RecurringSchedule;
use DateTimeImmutable;

final class CashFlowProjectionService
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly RecurringMovementRepository $recurring,
        private readonly FinancialSpaceService $spaces,
    ) {
    }

    public function project(int $userId, mixed $spaceKey): array
    {
        $space = $this->spaces->resolve($userId, $spaceKey);
        $accounts = array_values(array_filter(
            $this->accounts->listForUser($userId, true),
            static fn (array $account): bool => $account['scope'] === $space['scope']
                && self::nullableId($account['owner_user_id']) === $space['owner_user_id']
                && self::nullableId($account['household_id']) === $space['household_id']
        ));
        $accountIds = [];
        $startingCents = 0;
        foreach ($accounts as $account) {
            $accountIds[(int) $account['id']] = true;
            $startingCents += self::cents((string) $account['current_balance']);
        }

        $today = new DateTimeImmutable('today');
        $horizon = $today->modify('+90 days');
        $events = [];
        foreach ($this->recurring->listForUser($userId) as $schedule) {
            if (
                $schedule['status'] !== 'active'
                || $schedule['scope'] !== $space['scope']
                || self::nullableId($schedule['owner_user_id']) !== $space['owner_user_id']
                || self::nullableId($schedule['household_id']) !== $space['household_id']
                || !isset($accountIds[(int) $schedule['account_id']])
                || $schedule['occurrence_status'] === 'confirmed'
            ) {
                continue;
            }

            $due = new DateTimeImmutable((string) $schedule['next_due_date']);
            if ($due < $today) {
                $due = $today;
            }
            while ($due <= $horizon && ($schedule['end_date'] === null || $due->format('Y-m-d') <= $schedule['end_date'])) {
                $amountCents = self::cents((string) $schedule['amount']);
                $events[] = [
                    'date' => $due->format('Y-m-d'),
                    'type' => $schedule['operation_type'],
                    'description' => (string) $schedule['description'],
                    'amount' => (string) $schedule['amount'],
                    'delta_cents' => $schedule['operation_type'] === 'income' ? $amountCents : -$amountCents,
                ];
                $due = new DateTimeImmutable(RecurringSchedule::nextDate(
                    $due->format('Y-m-d'),
                    (string) $schedule['frequency'],
                    (int) $schedule['interval_count'],
                    (int) $schedule['anchor_day']
                ));
            }
        }

        usort($events, static fn (array $left, array $right): int => [$left['date'], $left['description']] <=> [$right['date'], $right['description']]);
        $runningCents = $startingCents;
        $eventIndex = 0;
        $checkpoints = [];
        foreach ([30, 60, 90] as $days) {
            $checkpointDate = $today->modify('+' . $days . ' days');
            while (isset($events[$eventIndex]) && $events[$eventIndex]['date'] <= $checkpointDate->format('Y-m-d')) {
                $runningCents += $events[$eventIndex]['delta_cents'];
                $eventIndex++;
            }
            $checkpoints[] = [
                'days' => $days,
                'date' => $checkpointDate->format('Y-m-d'),
                'projected_balance' => self::decimal($runningCents),
            ];
        }

        return [
            'space' => $space,
            'today' => $today->format('Y-m-d'),
            'starting_balance' => self::decimal($startingCents),
            'account_count' => count($accounts),
            'checkpoints' => $checkpoints,
            'events' => array_slice($events, 0, 100),
        ];
    }

    private static function cents(string $amount): int
    {
        if (!preg_match('/\A-?\d{1,13}\.\d{2}\z/', $amount)) {
            throw new DomainException('Se encontró un importe que no se puede proyectar.');
        }

        return (int) str_replace('.', '', $amount);
    }

    private static function decimal(int $cents): string
    {
        $negative = $cents < 0;
        $absolute = abs($cents);
        if ($absolute > 999_999_999_999_999) {
            throw new DomainException('El saldo conjunto supera el límite que puede presentar la aplicación.');
        }

        return ($negative ? '-' : '')
            . intdiv($absolute, 100)
            . '.'
            . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function nullableId(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
