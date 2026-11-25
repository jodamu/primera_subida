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
                    GROUP BY operation_id
                ) cash ON cash.operation_id = o.id
                LEFT JOIN (
                    SELECT operation_id, SUM(amount) AS debt_amount,
                           SUM(interest_amount) AS interest_amount
                    FROM liability_movements
                    GROUP BY operation_id
                ) debt ON debt.operation_id = o.id
                LEFT JOIN categories c ON c.id = COALESCE(o.category_id, original.category_id)
                WHERE o.confirmed_at IS NOT NULL
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
            'year' => (int) $yearValue,
            'month' => (int) $monthValue,
            'household_id' => $householdValue === null ? null : (int) $householdValue,
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
}
