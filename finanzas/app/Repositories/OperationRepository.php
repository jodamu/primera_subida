<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class OperationRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function categories(): array
    {
        return $this->db->query(
            'SELECT id, code, name, kind
             FROM categories
             WHERE is_active = 1
             ORDER BY kind, sort_order, name'
        )->fetchAll();
    }

    public function findCategory(int $categoryId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, kind
             FROM categories
             WHERE id = :id AND is_active = 1
             LIMIT 1'
        );
        $statement->execute(['id' => $categoryId]);
        $category = $statement->fetch();

        return $category ?: null;
    }

    public function findExpenseCategoryByCode(string $code): ?int
    {
        $statement = $this->db->prepare(
            'SELECT id
             FROM categories
             WHERE code = :code AND kind = "expense" AND is_active = 1
             LIMIT 1'
        );
        $statement->execute(['code' => $code]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function findByIdempotencyKey(int $userId, string $key): ?int
    {
        $statement = $this->db->prepare(
            'SELECT id
             FROM operations
             WHERE created_by = :created_by AND idempotency_key = :idempotency_key
             LIMIT 1'
        );
        $statement->execute([
            'created_by' => $userId,
            'idempotency_key' => $key,
        ]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function findByImportFingerprint(int $userId, string $fingerprint): ?int
    {
        $statement = $this->db->prepare(
            'SELECT id
             FROM operations
             WHERE created_by = :created_by AND import_fingerprint = :fingerprint
             LIMIT 1'
        );
        $statement->execute(['created_by' => $userId, 'fingerprint' => $fingerprint]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function create(array $data): int
    {
        $data += ['import_fingerprint' => null];
        $statement = $this->db->prepare(
            'INSERT INTO operations (
                operation_type, status, scope, owner_user_id, household_id, created_by,
                category_id, description, effective_date, idempotency_key, import_fingerprint,
                reversal_of_operation_id, confirmed_at
             ) VALUES (
                :operation_type, "confirmed", :scope, :owner_user_id, :household_id, :created_by,
                :category_id, :description, :effective_date, :idempotency_key, :import_fingerprint,
                :reversal_of_operation_id, UTC_TIMESTAMP()
             )'
        );
        foreach ($data as $name => $value) {
            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_int($value), is_bool($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue(':' . $name, $value, $type);
        }
        $statement->execute();

        return (int) $this->db->lastInsertId();
    }

    public function addMovement(int $operationId, int $accountId, string $direction, string $amount): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO movements (operation_id, account_id, direction, amount)
             VALUES (:operation_id, :account_id, :direction, :amount)'
        );
        $statement->execute([
            'operation_id' => $operationId,
            'account_id' => $accountId,
            'direction' => $direction,
            'amount' => $amount,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function addTransfer(int $operationId, int $sourceMovementId, int $destinationMovementId): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO transfers (operation_id, source_movement_id, destination_movement_id)
             VALUES (:operation_id, :source_movement_id, :destination_movement_id)'
        );
        $statement->execute([
            'operation_id' => $operationId,
            'source_movement_id' => $sourceMovementId,
            'destination_movement_id' => $destinationMovementId,
        ]);
    }

    public function findForUpdate(int $operationId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT *
             FROM operations
             WHERE id = :id
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['id' => $operationId]);
        $operation = $statement->fetch();

        return $operation ?: null;
    }

    public function movementsForOperation(int $operationId): array
    {
        $statement = $this->db->prepare(
            'SELECT m.id, m.account_id, m.direction, m.amount,
                    a.scope, a.owner_user_id, a.household_id, a.status AS account_status
             FROM movements m
             INNER JOIN accounts a ON a.id = m.account_id
             WHERE m.operation_id = :operation_id
             ORDER BY a.id, m.id'
        );
        $statement->execute(['operation_id' => $operationId]);

        return $statement->fetchAll();
    }

    public function liabilityMovementsForOperation(int $operationId): array
    {
        $statement = $this->db->prepare(
            'SELECT lm.id, lm.liability_id, lm.direction, lm.amount, lm.interest_amount,
                    l.scope, l.owner_user_id, l.household_id, l.status AS liability_status,
                    l.kind, l.credit_limit
             FROM liability_movements lm
             INNER JOIN liabilities l ON l.id = lm.liability_id
             WHERE lm.operation_id = :operation_id
             ORDER BY l.id, lm.id'
        );
        $statement->execute(['operation_id' => $operationId]);

        return $statement->fetchAll();
    }

    public function addLiabilityMovement(
        int $operationId,
        int $liabilityId,
        string $direction,
        string $amount,
        string $interestAmount = '0.00'
    ): void {
        $statement = $this->db->prepare(
            'INSERT INTO liability_movements (
                operation_id, liability_id, direction, amount, interest_amount
             ) VALUES (
                :operation_id, :liability_id, :direction, :amount, :interest_amount
             )'
        );
        $statement->execute([
            'operation_id' => $operationId,
            'liability_id' => $liabilityId,
            'direction' => $direction,
            'amount' => $amount,
            'interest_amount' => $interestAmount,
        ]);
    }

    public function cancel(int $operationId): void
    {
        $statement = $this->db->prepare(
            'UPDATE operations
             SET status = "cancelled"
             WHERE id = :id AND status = "confirmed"'
        );
        $statement->execute(['id' => $operationId]);
    }

    public function recentForUser(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT o.id, o.operation_type, o.status, o.scope, o.description,
                    o.effective_date, o.created_at, o.reversal_of_operation_id,
                    c.name AS category_name, summary.amount, summary.account_summary
             FROM operations o
             INNER JOIN (
                SELECT entries.operation_id,
                       MAX(entries.amount) AS amount,
                       GROUP_CONCAT(entries.item_summary ORDER BY entries.sort_id SEPARATOR " · ") AS account_summary
                FROM (
                    SELECT m.operation_id,
                           CAST(m.amount AS DECIMAL(15,2)) AS amount,
                           CONCAT(a.name, CASE WHEN m.direction = "outflow" THEN " (salida)" ELSE " (entrada)" END) AS item_summary,
                           m.id AS sort_id
                    FROM movements m
                    INNER JOIN accounts a ON a.id = m.account_id
                    UNION ALL
                    SELECT lm.operation_id,
                           CAST(lm.amount + lm.interest_amount AS DECIMAL(15,2)) AS amount,
                           CONCAT(
                               l.name,
                               CASE WHEN lm.direction = "increase" THEN " (deuda +)" ELSE " (abono)" END
                           ) AS item_summary,
                           lm.id AS sort_id
                    FROM liability_movements lm
                    INNER JOIN liabilities l ON l.id = lm.liability_id
                ) entries
                GROUP BY entries.operation_id
             ) summary ON summary.operation_id = o.id
             LEFT JOIN categories c ON c.id = o.category_id
             WHERE o.confirmed_at IS NOT NULL
               AND (
                    (o.scope = "personal" AND o.owner_user_id = :owner_user_id)
                    OR
                    (o.scope = "household" AND EXISTS (
                        SELECT 1
                        FROM household_members hm
                        WHERE hm.household_id = o.household_id
                          AND hm.user_id = :member_user_id
                          AND hm.status = "active"
                    ))
               )
             ORDER BY o.effective_date DESC, o.id DESC
             LIMIT 50'
        );
        $statement->execute([
            'owner_user_id' => $userId,
            'member_user_id' => $userId,
        ]);

        return $statement->fetchAll();
    }
}
