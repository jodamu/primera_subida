<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class CsvImportRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function existingFingerprints(int $userId, array $fingerprints): array
    {
        $existing = [];
        foreach (array_chunk(array_values(array_unique($fingerprints)), 400) as $chunk) {
            if (!$chunk) {
                continue;
            }
            $placeholders = [];
            $parameters = ['user_id' => $userId];
            foreach ($chunk as $index => $fingerprint) {
                $placeholder = 'fingerprint_' . $index;
                $placeholders[] = ':' . $placeholder;
                $parameters[$placeholder] = $fingerprint;
            }
            $statement = $this->db->prepare(
                'SELECT import_fingerprint
                 FROM operations
                 WHERE created_by = :user_id
                   AND import_fingerprint IN (' . implode(', ', $placeholders) . ')'
            );
            $statement->execute($parameters);
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $fingerprint) {
                $existing[(string) $fingerprint] = true;
            }
        }

        return $existing;
    }

    public function createBatch(int $userId, int $accountId, array $rows): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO csv_import_batches (created_by, account_id, row_count)
             VALUES (:created_by, :account_id, :row_count)'
        );
        $statement->execute([
            'created_by' => $userId,
            'account_id' => $accountId,
            'row_count' => count($rows),
        ]);
        $batchId = (int) $this->db->lastInsertId();
        $insertRow = $this->db->prepare(
            'INSERT INTO csv_import_rows (
                batch_id, line_number, operation_type, category_id, effective_date,
                amount, description, import_fingerprint, is_duplicate
             ) VALUES (
                :batch_id, :line_number, :operation_type, :category_id, :effective_date,
                :amount, :description, :import_fingerprint, :is_duplicate
             )'
        );
        foreach ($rows as $row) {
            $insertRow->execute([
                'batch_id' => $batchId,
                'line_number' => $row['line_number'],
                'operation_type' => $row['operation_type'],
                'category_id' => $row['category_id'],
                'effective_date' => $row['effective_date'],
                'amount' => $row['amount'],
                'description' => $row['description'],
                'import_fingerprint' => $row['import_fingerprint'],
                'is_duplicate' => $row['is_duplicate'] ? 1 : 0,
            ]);
        }

        return $batchId;
    }

    public function recentForUser(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT b.id, b.status, b.row_count, b.created_at, b.confirmed_at, a.name AS account_name
             FROM csv_import_batches b
             INNER JOIN accounts a ON a.id = b.account_id
             WHERE b.created_by = :user_id
             ORDER BY b.id DESC
             LIMIT 20'
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll();
    }

    public function batchForUser(int $batchId, int $userId, bool $lock = false): ?array
    {
        $sql = 'SELECT b.id, b.account_id, b.status, b.row_count, a.name AS account_name
                FROM csv_import_batches b
                INNER JOIN accounts a ON a.id = b.account_id
                WHERE b.id = :batch_id AND b.created_by = :user_id
                LIMIT 1';
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->db->prepare($sql);
        $statement->execute(['batch_id' => $batchId, 'user_id' => $userId]);
        $batch = $statement->fetch();

        return $batch ?: null;
    }

    public function rowsForBatch(int $batchId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, line_number, operation_type, category_id, effective_date,
                    CAST(amount AS CHAR) AS amount, description, import_fingerprint,
                    is_duplicate, operation_id
             FROM csv_import_rows
             WHERE batch_id = :batch_id
             ORDER BY line_number'
        );
        $statement->execute(['batch_id' => $batchId]);

        return $statement->fetchAll();
    }

    public function attachOperations(int $batchId, array $operationIds): void
    {
        $statement = $this->db->prepare(
            'UPDATE csv_import_rows
             SET operation_id = :operation_id
             WHERE batch_id = :batch_id AND line_number = :line_number'
        );
        foreach ($operationIds as $lineNumber => $operationId) {
            $statement->execute([
                'operation_id' => $operationId,
                'batch_id' => $batchId,
                'line_number' => $lineNumber,
            ]);
        }
    }

    public function completeBatch(int $batchId): void
    {
        $statement = $this->db->prepare(
            'UPDATE csv_import_batches
             SET status = "confirmed", confirmed_at = UTC_TIMESTAMP()
             WHERE id = :id AND status = "preview"'
        );
        $statement->execute(['id' => $batchId]);
    }

    public function cancelBatch(int $batchId, int $userId): void
    {
        $statement = $this->db->prepare(
            'UPDATE csv_import_batches
             SET status = "cancelled"
             WHERE id = :batch_id AND created_by = :user_id AND status = "preview"'
        );
        $statement->execute(['batch_id' => $batchId, 'user_id' => $userId]);
    }
}
