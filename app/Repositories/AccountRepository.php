<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use PDOStatement;

final class AccountRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function types(): array
    {
        return $this->db->query(
            'SELECT id, code, name
             FROM account_types
             WHERE is_active = 1
             ORDER BY sort_order, name'
        )->fetchAll();
    }

    public function listForUser(int $userId, bool $activeOnly = false): array
    {
        $statusFilter = $activeOnly ? 'AND a.status = "active"' : '';
        $statement = $this->db->prepare(
            'SELECT a.id, a.name, a.scope, a.owner_user_id, a.household_id,
                    a.initial_balance, a.initial_balance_date, a.currency,
                    a.overdraft_enabled, a.overdraft_limit, a.status, a.archived_at,
                    t.code AS type_code, t.name AS type_name,
                    CAST(
                        CAST(a.initial_balance AS DECIMAL(15,2))
                        + COALESCE(b.balance_delta, 0.00)
                        AS DECIMAL(15,2)
                    ) AS current_balance,
                    CASE
                        WHEN (a.scope = "personal" AND a.owner_user_id = :manager_owner_id)
                          OR (a.scope = "household" AND EXISTS (
                              SELECT 1
                              FROM household_members hm
                              WHERE hm.household_id = a.household_id
                                AND hm.user_id = :manager_member_user_id
                                AND hm.status = "active"
                                AND hm.role IN ("owner", "admin")
                          ))
                        THEN 1 ELSE 0
                    END AS can_manage
             FROM accounts a
             INNER JOIN account_types t ON t.id = a.account_type_id
             LEFT JOIN (
                SELECT m.account_id,
                       SUM(
                           CASE WHEN m.direction = "inflow"
                                THEN CAST(m.amount AS DECIMAL(15,2))
                                ELSE CAST(m.amount AS DECIMAL(15,2)) * -1
                           END
                       ) AS balance_delta
                FROM movements m
                INNER JOIN operations o ON o.id = m.operation_id
                WHERE o.confirmed_at IS NOT NULL
                GROUP BY m.account_id
             ) b ON b.account_id = a.id
             WHERE (
                (a.scope = "personal" AND a.owner_user_id = :owner_user_id)
                OR
                (a.scope = "household" AND EXISTS (
                    SELECT 1
                    FROM household_members hm
                    WHERE hm.household_id = a.household_id
                      AND hm.user_id = :member_user_id
                      AND hm.status = "active"
                ))
             ) ' . $statusFilter . '
             ORDER BY a.status, a.name, a.id'
        );
        $statement->execute([
            'owner_user_id' => $userId,
            'member_user_id' => $userId,
            'manager_owner_id' => $userId,
            'manager_member_user_id' => $userId,
        ]);

        return $statement->fetchAll();
    }

    public function findForUpdate(int $accountId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT a.*, t.code AS type_code, t.name AS type_name
             FROM accounts a
             INNER JOIN account_types t ON t.id = a.account_type_id
             WHERE a.id = :id
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['id' => $accountId]);
        $account = $statement->fetch();

        return $account ?: null;
    }

    public function findType(int $typeId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, code, name
             FROM account_types
             WHERE id = :id AND is_active = 1
             LIMIT 1'
        );
        $statement->execute(['id' => $typeId]);
        $type = $statement->fetch();

        return $type ?: null;
    }

    public function memberRole(int $householdId, int $userId, bool $lock = false): ?string
    {
        $sql = 'SELECT role
                FROM household_members
                WHERE household_id = :household_id
                  AND user_id = :user_id
                  AND status = "active"
                LIMIT 1';
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->db->prepare($sql);
        $statement->execute([
            'household_id' => $householdId,
            'user_id' => $userId,
        ]);
        $role = $statement->fetchColumn();

        return $role === false ? null : (string) $role;
    }

    public function create(array $data): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO accounts (
                account_type_id, name, scope, owner_user_id, household_id, created_by,
                initial_balance, initial_balance_date, currency, overdraft_enabled, overdraft_limit
             ) VALUES (
                :account_type_id, :name, :scope, :owner_user_id, :household_id, :created_by,
                :initial_balance, :initial_balance_date, :currency, :overdraft_enabled, :overdraft_limit
             )'
        );
        $this->bindValues($statement, $data);
        $statement->execute();

        return (int) $this->db->lastInsertId();
    }

    public function balanceForAccount(int $accountId): string
    {
        $statement = $this->db->prepare(
            'SELECT CAST(
                CAST(a.initial_balance AS DECIMAL(15,2))
                + COALESCE(SUM(
                    CASE WHEN o.confirmed_at IS NULL THEN 0.00
                         WHEN m.direction = "inflow" THEN CAST(m.amount AS DECIMAL(15,2))
                         ELSE CAST(m.amount AS DECIMAL(15,2)) * -1
                    END
                ), 0.00)
                AS DECIMAL(15,2)
             ) AS current_balance
             FROM accounts a
             LEFT JOIN movements m ON m.account_id = a.id
             LEFT JOIN operations o ON o.id = m.operation_id
             WHERE a.id = :account_id
             GROUP BY a.id, a.initial_balance'
        );
        $statement->execute(['account_id' => $accountId]);

        return (string) $statement->fetchColumn();
    }

    public function archive(int $accountId): void
    {
        $statement = $this->db->prepare(
            'UPDATE accounts
             SET status = "archived", archived_at = UTC_TIMESTAMP()
             WHERE id = :id AND status = "active"'
        );
        $statement->execute(['id' => $accountId]);
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
