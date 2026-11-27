<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\FinancialPlanningRepository;
use App\Repositories\RecurringMovementRepository;
use App\Support\FinancialPeriod;
use DateTimeImmutable;
use DateTimeZone;

final class FinancialAlertService
{
    public function __construct(
        private readonly FinancialSpaceService $spaces,
        private readonly FinancialSettingsService $settings,
        private readonly FinancialPlanningRepository $planning,
        private readonly LiabilityService $liabilities,
        private readonly RecurringMovementRepository $recurring,
    ) {
    }

    public function forUser(int $userId): array
    {
        $today = new DateTimeImmutable('today');
        $lastAlertDay = $today->modify('+7 days')->format('Y-m-d');
        $alerts = [];
        foreach ($this->liabilities->listForUser($userId, true) as $liability) {
            if ($liability['kind'] !== 'credit_card' || (string) $liability['current_balance'] === '0.00') {
                continue;
            }
            $dueDate = self::nextDueDate((int) $liability['payment_due_day'], $today);
            if ($dueDate > $lastAlertDay) {
                continue;
            }
            $spaceKey = $liability['scope'] === 'household'
                ? 'household:' . (int) $liability['household_id']
                : 'personal';
            $alerts[] = [
                'kind' => 'debt',
                'severity' => 'info',
                'date' => $dueDate,
                'title' => 'Próxima fecha de pago',
                'message' => $liability['name'] . ': revisa la fecha límite y el valor del pago con la entidad.',
                'href' => '/debt-plan?space=' . rawurlencode($spaceKey),
            ];
        }

        foreach ($this->recurring->listForUser($userId) as $schedule) {
            if (
                $schedule['status'] !== 'active'
                || $schedule['occurrence_status'] === 'confirmed'
                || $schedule['next_due_date'] > $lastAlertDay
            ) {
                continue;
            }
            $isOverdue = $schedule['next_due_date'] < $today->format('Y-m-d');
            $alerts[] = [
                'kind' => 'recurring',
                'severity' => $isOverdue ? 'warning' : 'info',
                'date' => (string) $schedule['next_due_date'],
                'title' => $isOverdue ? 'Movimiento recurrente pendiente' : 'Movimiento próximo',
                'message' => $schedule['description'] . ' · ' . $schedule['next_due_date']
                    . ' · requiere confirmación manual.',
                'href' => '/recurring',
            ];
        }

        foreach ($this->spaces->listForUser($userId) as $space) {
            $configuration = $this->settings->get($userId, $space['key']);
            $timezone = new DateTimeZone($configuration['settings']['timezone']);
            $spaceToday = new DateTimeImmutable('today', $timezone);
            [$periodStart, $periodEnd] = FinancialPeriod::containing(
                (int) $configuration['settings']['month_start_day'],
                $spaceToday
            );
            $threshold = (int) $configuration['settings']['budget_alert_percent'];
            foreach ($this->planning->budgetsForSpace($space, $periodStart, $periodEnd) as $budget) {
                $limitCents = (int) str_replace('.', '', (string) $budget['amount_limit']);
                $spentCents = (int) str_replace('.', '', (string) $budget['spent']);
                if ($limitCents <= 0 || $spentCents * 100 < $limitCents * $threshold) {
                    continue;
                }
                $alerts[] = [
                    'kind' => 'budget',
                    'severity' => 'warning',
                    'date' => $spaceToday->format('Y-m-d'),
                    'title' => 'Presupuesto cerca o sobre el límite',
                    'message' => $space['name'] . ' · ' . $budget['category_name']
                        . ' · ' . $threshold . '% del presupuesto configurado.',
                    'href' => '/budgets?space=' . rawurlencode($space['key']),
                ];
            }
        }

        usort($alerts, static fn (array $left, array $right): int => [$left['date'], $left['severity']] <=> [$right['date'], $right['severity']]);

        return array_slice($alerts, 0, 20);
    }

    private static function nextDueDate(int $day, DateTimeImmutable $today): string
    {
        $due = $today->setDate((int) $today->format('Y'), (int) $today->format('n'), $day);
        if ($due < $today) {
            $nextMonth = $today->modify('first day of next month');
            $due = $nextMonth->setDate((int) $nextMonth->format('Y'), (int) $nextMonth->format('n'), $day);
        }

        return $due->format('Y-m-d');
    }
}
