<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use PDOStatement;

final class LiabilityRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function listForUser(int $userId, bool $activeOnly = false): array
    {
        $statusFilter = $activeOnly ? "AND l.status = 'active'" : '';
        $statement = $this->db->prepare(
            'SELECT l.id, l.kind, l.name, l.creditor, l.scope, l.owner_user_id, l.household_id,
                    l.initial_balance, l.initial_balance_date, l.credit_limit,
                    l.statement_close_day, l.payment_due_day, l.annual_interest_rate,
                    l.currency, l.status, l.archived_at,
                    CAST(
                        CAST(l.initial_balance AS DECIMAL(15,2))
                        + COALESCE(b.balance_delta, 0.00)
                        AS DECIMAL(15,2)
                    ) AS current_balance,
                    CASE WHEN l.kind = "credit_card"
                         THEN CAST(l.credit_limit - l.initial_balance - COALESCE(b.balance_delta, 0.00) AS DECIMAL(15,2))
                         ELSE NULL
                    END AS available_credit,
                    CASE
                        WHEN (l.scope = "personal" AND l.owner_user_id = :manager_owner_id)
                          OR (l.scope = "household" AND EXISTS (
                              SELECT 1
                              FROM household_members hm
                              WHERE hm.household_id = l.household_id
                                AND hm.user_id = :manager_member_user_id
                                AND hm.status = "active"
                                AND hm.role IN ("owner", "admin")
                          ))
                        THEN 1 ELSE 0
                    END AS can_manage
             FROM liabilities l
             LEFT JOIN (
                SELECT liability_id,
                       SUM(CASE WHEN direction = "increase" THEN amount ELSE amount * -1 END) AS balance_delta
                FROM liability_movements
                GROUP BY liability_id
             ) b ON b.liability_id = l.id
             WHERE (
                (l.scope = "personal" AND l.owner_user_id = :owner_user_id)
                OR
                (l.scope = "household" AND EXISTS (
                    SELECT 1
                    FROM household_members hm
                    WHERE hm.household_id = l.household_id
                      AND hm.user_id = :member_user_id
                      AND hm.status = "active"
                ))
             ) ' . $statusFilter . '
             ORDER BY l.status, l.name, l.id'
        );
        $statement->execute([
            'owner_user_id' => $userId,
            'member_user_id' => $userId,
            'manager_owner_id' => $userId,
            'manager_member_user_id' => $userId,
        ]);

        return $statement->fetchAll();
    }

    public function find(int $liabilityId): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM liabilities WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $liabilityId]);
        $liability = $statement->fetch();

        return $liability ?: null;
    }

    public function findForUpdate(int $liabilityId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT *
             FROM liabilities
             WHERE id = :id
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['id' => $liabilityId]);
        $liability = $statement->fetch();

        return $liability ?: null;
    }

    public function balanceForLiability(int $liabilityId): string
    {
        $statement = $this->db->prepare(
            'SELECT CAST(
                CAST(l.initial_balance AS DECIMAL(15,2))
                + COALESCE(SUM(
                    CASE WHEN lm.direction = "increase" THEN lm.amount ELSE lm.amount * -1 END
                ), 0.00)
                AS DECIMAL(15,2)
             ) AS current_balance
             FROM liabilities l
             LEFT JOIN liability_movements lm ON lm.liability_id = l.id
             WHERE l.id = :liability_id
             GROUP BY l.id, l.initial_balance'
        );
        $statement->execute(['liability_id' => $liabilityId]);

        return (string) $statement->fetchColumn();
    }

    public function create(array $data): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO liabilities (
                kind, name, creditor, scope, owner_user_id, household_id, created_by,
                initial_balance, initial_balance_date, credit_limit, statement_close_day,
                payment_due_day, annual_interest_rate, currency
             ) VALUES (
                :kind, :name, :creditor, :scope, :owner_user_id, :household_id, :created_by,
                :initial_balance, :initial_balance_date, :credit_limit, :statement_close_day,
                :payment_due_day, :annual_interest_rate, "COP"
             )'
        );
        $this->bindValues($statement, $data);
        $statement->execute();

        return (int) $this->db->lastInsertId();
    }

    public function archive(int $liabilityId): void
    {
        $statement = $this->db->prepare(
            'UPDATE liabilities
             SET status = "archived", archived_at = UTC_TIMESTAMP()
             WHERE id = :id AND status = "active"'
        );
        $statement->execute(['id' => $liabilityId]);
    }

    private function bindValues(PDOStatement $statement, array $data): void
    {
        foreach ($data as $name => $value) {
            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_int($value), is_bool($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue(':' . $name, $value, $type);
        }
    }
}
