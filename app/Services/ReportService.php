<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DomainException;
use PDO;

final class ReportService
{
    public function __construct(
        private readonly PDO $db,
        private readonly HouseholdService $households,
    ) {
    }

    public function spacesForUser(int $userId): array
    {
        return $this->households->forUser($userId);
    }

    public function monthly(int $userId, mixed $year, mixed $month, mixed $householdId = null): array
    {
        $yearValue = filter_var($year, FILTER_VALIDATE_INT);
        $monthValue = filter_var($month, FILTER_VALIDATE_INT);
        if (!$yearValue || $yearValue < 2000 || $yearValue > 2100 || !$monthValue || $monthValue < 1 || $monthValue > 12) {
            throw new DomainException('Selecciona un mes y año válidos.');
        }

        $householdValue = $householdId === null || $householdId === '' || $householdId === 'personal'
            ? null
            : filter_var($householdId, FILTER_VALIDATE_INT);
        if ($householdId !== null && $householdId !== '' && $householdId !== 'personal' && (!$householdValue || $householdValue < 1)) {
            throw new DomainException('Selecciona un espacio financiero válido.');
        }
        if ($householdValue !== null) {
            $isMember = false;
            foreach ($this->households->forUser($userId) as $household) {
                if ((int) $household['id'] === (int) $householdValue) {
                    $isMember = true;
                    break;
                }
            }
            if (!$isMember) {
                throw new DomainException('No tienes acceso a ese hogar.');
            }
        }

        $monthStart = sprintf('%04d-%02d-01', $yearValue, $monthValue);
        $monthEnd = date('Y-m-t', strtotime($monthStart));
        $scopeSql = $householdValue === null
            ? 'o.scope = "personal" AND o.owner_user_id = :personal_user_id'
            : 'o.scope = "household" AND o.household_id = :household_id';
        $params = [
            'month_start' => $monthStart,
            'month_end' => $monthEnd,
        ];
        if ($householdValue === null) {
            $params['personal_user_id'] = $userId;
        } else {
            $params['household_id'] = (int) $householdValue;
        }

        return [
            'year' => (int) $yearValue,
            'month' => (int) $monthValue,
            'household_id' => $householdValue === null ? null : (int) $householdValue,
        ] + $this->aggregatePeriod($scopeSql, $params);
    }

    public function totalsForSpacePeriod(array $space, string $start, string $end): array
    {
        $personal = $space['scope'] === 'personal';
        $scopeSql = $personal
            ? 'o.scope = "personal" AND o.owner_user_id = :personal_user_id'
            : 'o.scope = "household" AND o.household_id = :household_id';
        $params = ['month_start' => $start, 'month_end' => $end];
        $params[$personal ? 'personal_user_id' : 'household_id'] = (int) (
            $personal ? $space['owner_user_id'] : $space['household_id']
        );

        return $this->aggregatePeriod($scopeSql, $params);
    }

    private function aggregatePeriod(string $scopeSql, array $params): array
    {
        $statement = $this->db->prepare(
            'WITH eligible AS (
                SELECT o.id, o.operation_type, o.effective_date,
                       COALESCE(o.category_id, original.category_id) AS category_id,
                       original.operation_type AS original_type,
                       COALESCE(cash.cash_amount, 0.00) AS cash_amount,
                       COALESCE(debt.debt_amount, 0.00) AS debt_amount,
                       COALESCE(debt.interest_amount, 0.00) AS interest_amount,
                       COALESCE(c.name, "Sin categoría") AS category_name
                FROM operations o
                LEFT JOIN operations original ON original.id = o.reversal_of_operation_id
                LEFT JOIN (
                    SELECT operation_id, SUM(amount) AS cash_amount
                    FROM movements
                    WHERE account_id IN (SELECT id FROM accounts WHERE currency = "COP")
                    GROUP BY operation_id
                ) cash ON cash.operation_id = o.id
                LEFT JOIN (
                    SELECT operation_id, SUM(amount) AS debt_amount,
                           SUM(interest_amount) AS interest_amount
                    FROM liability_movements
                    WHERE liability_id IN (SELECT id FROM liabilities WHERE currency = "COP")
                    GROUP BY operation_id
                ) debt ON debt.operation_id = o.id
                LEFT JOIN categories c ON c.id = COALESCE(o.category_id, original.category_id)
                WHERE o.confirmed_at IS NOT NULL
                  AND (cash.cash_amount IS NOT NULL OR debt.debt_amount IS NOT NULL)
                  AND o.effective_date BETWEEN :month_start AND :month_end
                  AND (' . $scopeSql . ')
             ),
             events AS (
                SELECT "income" AS kind, category_name,
                       CAST(cash_amount AS DECIMAL(15,2)) AS amount_delta
                FROM eligible
                WHERE operation_type = "income"
                UNION ALL
                SELECT "expense", category_name,
                       CAST(GREATEST(cash_amount, debt_amount) AS DECIMAL(15,2))
                FROM eligible
                WHERE operation_type = "expense"
                UNION ALL
                SELECT "expense", category_name,
                       CAST(interest_amount AS DECIMAL(15,2))
                FROM eligible
                WHERE operation_type = "liability_payment" AND interest_amount > 0.00
                UNION ALL
                SELECT original_type, category_name,
                       CAST(GREATEST(cash_amount, debt_amount) * -1 AS DECIMAL(15,2))
                FROM eligible
                WHERE operation_type = "reversal" AND original_type IN ("income", "expense")
                UNION ALL
                SELECT "expense", category_name,
                       CAST(interest_amount * -1 AS DECIMAL(15,2))
                FROM eligible
                WHERE operation_type = "reversal"
                  AND original_type = "liability_payment"
                  AND interest_amount > 0.00
             ),
             grouped AS (
                SELECT kind, category_name, CAST(SUM(amount_delta) AS DECIMAL(15,2)) AS category_total
                FROM events
                GROUP BY kind, category_name
             )
             SELECT kind, category_name, CAST(category_total AS CHAR) AS category_total,
                    CAST(SUM(CASE WHEN kind = "income" THEN category_total ELSE 0.00 END) OVER () AS CHAR) AS income_total,
                    CAST(SUM(CASE WHEN kind = "expense" THEN category_total ELSE 0.00 END) OVER () AS CHAR) AS expense_total,
                    CAST(SUM(CASE WHEN kind = "income" THEN category_total ELSE -category_total END) OVER () AS CHAR) AS net_total
             FROM grouped
             ORDER BY kind, ABS(category_total) DESC, category_name'
        );
        $statement->execute($params);
        $rows = $statement->fetchAll();

        return [
            'income_total' => $rows ? (string) $rows[0]['income_total'] : '0.00',
            'expense_total' => $rows ? (string) $rows[0]['expense_total'] : '0.00',
            'net_total' => $rows ? (string) $rows[0]['net_total'] : '0.00',
            'categories' => array_map(
                static fn (array $row): array => [
                    'kind' => $row['kind'],
                    'name' => $row['category_name'],
                    'total' => (string) $row['category_total'],
                ],
                $rows
            ),
        ];
    }

    public function trends(int $userId, mixed $householdId = null): array
    {
        $currentMonth = new \DateTimeImmutable('first day of this month');
        $periods = [];
        $currentTotals = ['income' => 0, 'expense' => 0, 'net' => 0];
        $previousTotals = ['income' => 0, 'expense' => 0, 'net' => 0];

        for ($offset = 23; $offset >= 0; $offset--) {
            $date = $currentMonth->modify('-' . $offset . ' months');
            $report = $this->monthly($userId, $date->format('Y'), $date->format('n'), $householdId);
            $period = [
                'year' => (int) $date->format('Y'),
                'month' => (int) $date->format('n'),
                'income' => $report['income_total'],
                'expense' => $report['expense_total'],
                'net' => $report['net_total'],
            ];
            $isCurrentPeriod = $offset < 12;
            foreach (['income', 'expense', 'net'] as $kind) {
                $amount = $this->minorUnits($period[$kind]);
                if ($isCurrentPeriod) {
                    $currentTotals[$kind] += $amount;
                } else {
                    $previousTotals[$kind] += $amount;
                }
            }
            if ($isCurrentPeriod) {
                $periods[] = $period;
            }
        }

        return [
            'household_id' => $report['household_id'],
            'periods' => $periods,
            'current_totals' => $this->formatTotals($currentTotals),
            'previous_totals' => $this->formatTotals($previousTotals),
        ];
    }

    private function minorUnits(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        $absolute = $negative ? substr($amount, 1) : $amount;
        [$whole, $fraction] = array_pad(explode('.', $absolute, 2), 2, '00');
        $minorUnits = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');

        return $negative ? -$minorUnits : $minorUnits;
    }

    private function formatTotals(array $totals): array
    {
        $formatted = [];
        foreach ($totals as $kind => $amount) {
            $absolute = abs($amount);
            $formatted[$kind] = ($amount < 0 ? '-' : '')
                . intdiv($absolute, 100)
                . '.'
                . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
        }

        return $formatted;
    }
}
