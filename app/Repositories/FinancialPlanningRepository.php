<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use PDOStatement;

final class FinancialPlanningRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function expenseCategories(?array $space = null): array
    {
        $sql = 'SELECT id, name
             FROM categories
             WHERE kind = "expense" AND is_active = 1';
        $params = [];
        if ($space !== null) {
            if ($space['scope'] === 'personal') {
                $sql .= ' AND (scope IS NULL OR (scope = "personal" AND owner_user_id = :owner_user_id))';
                $params['owner_user_id'] = (int) $space['owner_user_id'];
            } else {
                $sql .= ' AND (scope IS NULL OR (scope = "household" AND household_id = :household_id))';
                $params['household_id'] = (int) $space['household_id'];
            }
        }
        $statement = $this->db->prepare($sql . ' ORDER BY sort_order, name');
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function budgetsForSpace(array $space, string $periodStart, string $periodEnd): array
    {
        $statement = $this->db->prepare(
            'SELECT b.id, b.category_id, c.name AS category_name,
                    CAST(b.amount_limit AS CHAR) AS amount_limit,
                    CAST(COALESCE(spent.amount, 0.00) AS CHAR) AS spent
             FROM budgets b
             INNER JOIN categories c ON c.id = b.category_id
             LEFT JOIN (
                SELECT COALESCE(o.category_id, original.category_id) AS category_id,
                       CAST(SUM(
                           CASE
                               WHEN o.operation_type = "expense"
                                   THEN GREATEST(COALESCE(cash.amount, 0.00), COALESCE(debt.amount, 0.00))
                               WHEN o.operation_type = "liability_payment"
                                   THEN COALESCE(debt.interest, 0.00)
                               WHEN o.operation_type = "reversal" AND original.operation_type = "expense"
                                   THEN GREATEST(COALESCE(cash.amount, 0.00), COALESCE(debt.amount, 0.00)) * -1
                               WHEN o.operation_type = "reversal" AND original.operation_type = "liability_payment"
                                   THEN COALESCE(debt.interest, 0.00) * -1
                               ELSE 0.00
                           END
                       ) AS DECIMAL(15,2)) AS amount
                FROM operations o
                LEFT JOIN operations original ON original.id = o.reversal_of_operation_id
                LEFT JOIN (
                    SELECT operation_id, SUM(amount) AS amount
                    FROM movements
                    WHERE account_id IN (SELECT id FROM accounts WHERE currency = "COP")
                    GROUP BY operation_id
                ) cash ON cash.operation_id = o.id
                LEFT JOIN (
                    SELECT operation_id, SUM(amount) AS amount,
                           SUM(interest_amount) AS interest
                    FROM liability_movements
                    WHERE liability_id IN (SELECT id FROM liabilities WHERE currency = "COP")
                    GROUP BY operation_id
                ) debt ON debt.operation_id = o.id
                WHERE o.scope = :spent_scope
                  AND (
                    (o.scope = "personal" AND o.owner_user_id = :spent_owner_id)
                    OR (o.scope = "household" AND o.household_id = :spent_household_id)
                  )
                  AND o.effective_date BETWEEN :spent_start AND :spent_end
                  AND o.confirmed_at IS NOT NULL
                  AND COALESCE(o.category_id, original.category_id) IS NOT NULL
                GROUP BY COALESCE(o.category_id, original.category_id)
             ) spent ON spent.category_id = b.category_id
             WHERE b.scope = :budget_scope
               AND b.space_key = :budget_space_key
               AND b.period_start = :budget_start
               AND b.period_end = :budget_end
             ORDER BY c.sort_order, c.name'
        );
        $statement->bindValue(':spent_scope', $space['scope'], PDO::PARAM_STR);
        $statement->bindValue(':spent_owner_id', $space['owner_user_id'], $space['owner_user_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':spent_household_id', $space['household_id'], $space['household_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':budget_scope', $space['scope'], PDO::PARAM_STR);
        $statement->bindValue(':budget_space_key', $space['owner_user_id'] ?? $space['household_id'], PDO::PARAM_INT);
        $statement->bindValue(':spent_start', $periodStart, PDO::PARAM_STR);
        $statement->bindValue(':spent_end', $periodEnd, PDO::PARAM_STR);
        $statement->bindValue(':budget_start', $periodStart, PDO::PARAM_STR);
        $statement->bindValue(':budget_end', $periodEnd, PDO::PARAM_STR);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function saveBudget(array $space, int $categoryId, string $periodStart, string $periodEnd, string $amount, int $userId): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO budgets (
                scope, owner_user_id, household_id, category_id,
                period_start, period_end, amount_limit, created_by, updated_by
             ) VALUES (
                :scope, :owner_user_id, :household_id, :category_id,
                :period_start, :period_end, :amount_limit, :created_by, :updated_by
             )
             ON DUPLICATE KEY UPDATE
                period_end = VALUES(period_end),
                amount_limit = VALUES(amount_limit),
                updated_by = VALUES(updated_by)'
        );
        $this->bindSpace($statement, $space);
        $statement->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
        $statement->bindValue(':period_start', $periodStart, PDO::PARAM_STR);
        $statement->bindValue(':period_end', $periodEnd, PDO::PARAM_STR);
        $statement->bindValue(':amount_limit', $amount, PDO::PARAM_STR);
        $statement->bindValue(':created_by', $userId, PDO::PARAM_INT);
        $statement->bindValue(':updated_by', $userId, PDO::PARAM_INT);
        $statement->execute();
    }

    public function goalsForSpace(array $space): array
    {
        $statement = $this->db->prepare(
            'SELECT g.id, g.name, g.target_date, g.status,
                    CAST(g.target_amount AS CHAR) AS target_amount,
                    CAST(COALESCE(SUM(
                        CASE WHEN e.direction = "contribution" THEN e.amount ELSE -e.amount END
                    ), 0.00) AS CHAR) AS saved_amount
             FROM savings_goals g
             LEFT JOIN savings_goal_entries e ON e.goal_id = g.id
             WHERE g.scope = :scope
               AND g.owner_user_id <=> :owner_user_id
               AND g.household_id <=> :household_id
             GROUP BY g.id
             ORDER BY g.status, g.target_date IS NULL, g.target_date, g.id DESC'
        );
        $this->bindSpace($statement, $space);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function createGoal(array $space, string $name, string $targetAmount, ?string $targetDate, int $userId): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO savings_goals (
                scope, owner_user_id, household_id, name, target_amount, target_date, created_by
             ) VALUES (
                :scope, :owner_user_id, :household_id, :name, :target_amount, :target_date, :created_by
             )'
        );
        $this->bindSpace($statement, $space);
        $statement->bindValue(':name', $name, PDO::PARAM_STR);
        $statement->bindValue(':target_amount', $targetAmount, PDO::PARAM_STR);
        $statement->bindValue(':target_date', $targetDate, $targetDate === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':created_by', $userId, PDO::PARAM_INT);
        $statement->execute();

        return (int) $this->db->lastInsertId();
    }

    public function goalForUpdate(int $goalId, array $space): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, status
             FROM savings_goals
             WHERE id = :id AND scope = :scope
               AND owner_user_id <=> :owner_user_id
               AND household_id <=> :household_id
             LIMIT 1 FOR UPDATE'
        );
        $statement->bindValue(':id', $goalId, PDO::PARAM_INT);
        $this->bindSpace($statement, $space);
        $statement->execute();
        $goal = $statement->fetch();

        return $goal ?: null;
    }

    public function goalBalance(int $goalId): string
    {
        $statement = $this->db->prepare(
            'SELECT CAST(COALESCE(SUM(
                CASE WHEN direction = "contribution" THEN amount ELSE -amount END
             ), 0.00) AS CHAR)
             FROM savings_goal_entries
             WHERE goal_id = :goal_id'
        );
        $statement->execute(['goal_id' => $goalId]);

        return (string) $statement->fetchColumn();
    }

    public function goalEntryExists(int $userId, string $key): bool
    {
        $statement = $this->db->prepare(
            'SELECT 1 FROM savings_goal_entries
             WHERE created_by = :user_id AND idempotency_key = :idempotency_key LIMIT 1'
        );
        $statement->execute(['user_id' => $userId, 'idempotency_key' => $key]);

        return $statement->fetchColumn() !== false;
    }

    public function addGoalEntry(int $goalId, int $userId, string $direction, string $amount, string $date, string $description, string $key): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO savings_goal_entries (
                goal_id, created_by, direction, amount, effective_date, description, idempotency_key
             ) VALUES (
                :goal_id, :created_by, :direction, :amount, :effective_date, :description, :idempotency_key
             )'
        );
        $statement->execute([
            'goal_id' => $goalId,
            'created_by' => $userId,
            'direction' => $direction,
            'amount' => $amount,
            'effective_date' => $date,
            'description' => $description,
            'idempotency_key' => $key,
        ]);
    }

    public function archiveGoal(int $goalId, array $space): void
    {
        $statement = $this->db->prepare(
            'UPDATE savings_goals
             SET status = "archived", archived_at = UTC_TIMESTAMP()
             WHERE id = :id AND scope = :scope
               AND owner_user_id <=> :owner_user_id
               AND household_id <=> :household_id
               AND status = "active"'
        );
        $statement->bindValue(':id', $goalId, PDO::PARAM_INT);
        $this->bindSpace($statement, $space);
        $statement->execute();
    }

    private function bindSpace(PDOStatement $statement, array $space): void
    {
        $statement->bindValue(':scope', $space['scope'], PDO::PARAM_STR);
        $statement->bindValue(
            ':owner_user_id',
            $space['owner_user_id'],
            $space['owner_user_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT
        );
        $statement->bindValue(
            ':household_id',
            $space['household_id'],
            $space['household_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT
        );
    }
}
