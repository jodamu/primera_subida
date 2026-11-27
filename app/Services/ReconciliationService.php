<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DomainException;
use App\Repositories\AccountRepository;
use App\Support\CsvValueParser;
use PDO;
use Throwable;

final class ReconciliationService
{
    private const MAX_FILE_BYTES = 2097152;
    private const MAX_ROWS = 1000;

    public function __construct(
        private readonly PDO $db,
        private readonly AccountRepository $accounts,
    ) {
    }

    public function accountsForUser(int $userId): array
    {
        return $this->accounts->listForUser($userId, true);
    }

    public function recentForUser(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT b.id, b.status, b.row_count, b.created_at, b.completed_at, a.name AS account_name
             FROM reconciliation_batches b
             INNER JOIN accounts a ON a.id = b.account_id
             WHERE b.created_by = :user_id
             ORDER BY b.id DESC
             LIMIT 20'
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll();
    }

    public function previewForUser(int $userId, mixed $batchId): array
    {
        $id = filter_var($batchId, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            throw new DomainException('Selecciona una conciliación válida.');
        }
        $batchStatement = $this->db->prepare(
            'SELECT b.id, b.account_id, b.status, b.row_count, b.created_at, a.name AS account_name
             FROM reconciliation_batches b
             INNER JOIN accounts a ON a.id = b.account_id
             WHERE b.id = :id AND b.created_by = :user_id
             LIMIT 1'
        );
        $batchStatement->execute(['id' => $id, 'user_id' => $userId]);
        $batch = $batchStatement->fetch();
        if (!$batch) {
            throw new DomainException('La conciliación no existe o no tienes acceso.');
        }
        $rows = $this->db->prepare(
            'SELECT id, line_number, transaction_date, operation_type,
                    CAST(amount AS CHAR) AS amount, description,
                    candidate_operation_id, matched_operation_id, status
             FROM reconciliation_rows
             WHERE batch_id = :batch_id
             ORDER BY line_number'
        );
        $rows->execute(['batch_id' => $id]);

        return ['batch' => $batch, 'rows' => $rows->fetchAll()];
    }

    public function createPreview(int $userId, array $input, mixed $file): int
    {
        $accountId = filter_var($input['account_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$accountId || $accountId < 1) {
            throw new DomainException('Selecciona una cuenta activa para conciliar.');
        }
        $account = null;
        foreach ($this->accountsForUser($userId) as $candidate) {
            if ((int) $candidate['id'] === (int) $accountId) {
                $account = $candidate;
                break;
            }
        }
        if ($account === null) {
            throw new DomainException('No tienes acceso a esa cuenta o está archivada.');
        }
        if (!is_array($file)) {
            throw new DomainException('Selecciona un archivo CSV válido.');
        }
        $temporaryPath = $file['tmp_name'] ?? null;
        $filename = $file['name'] ?? null;
        if (
            ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_string($temporaryPath)
            || !is_uploaded_file($temporaryPath)
            || !is_string($filename)
            || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'csv'
        ) {
            throw new DomainException('Selecciona un archivo CSV válido.');
        }
        $fileSize = filesize($temporaryPath);
        if ($fileSize === false || $fileSize < 1 || $fileSize > self::MAX_FILE_BYTES) {
            throw new DomainException('El CSV debe pesar entre 1 byte y 2 MB.');
        }
        $delimiter = $input['delimiter'] ?? ',';
        if (!is_string($delimiter) || !in_array($delimiter, [',', ';', "\t"], true)) {
            throw new DomainException('Selecciona un separador CSV válido.');
        }
        $handle = fopen($temporaryPath, 'rb');
        if ($handle === false) {
            throw new DomainException('No fue posible leer el archivo CSV.');
        }
        try {
            $rows = $this->parseRows($handle, $delimiter, $input, (int) $accountId);
        } finally {
            fclose($handle);
        }
        if (!$rows) {
            throw new DomainException('El CSV no contiene filas válidas para conciliar.');
        }

        $this->db->beginTransaction();
        try {
            $insertBatch = $this->db->prepare(
                'INSERT INTO reconciliation_batches (created_by, account_id, row_count)
                 VALUES (:user_id, :account_id, :row_count)'
            );
            $insertBatch->execute([
                'user_id' => $userId,
                'account_id' => $accountId,
                'row_count' => count($rows),
            ]);
            $batchId = (int) $this->db->lastInsertId();
            $insertRow = $this->db->prepare(
                'INSERT INTO reconciliation_rows (
                    batch_id, line_number, transaction_date, operation_type, amount, description,
                    fingerprint, candidate_operation_id
                 ) VALUES (
                    :batch_id, :line_number, :transaction_date, :operation_type, :amount, :description,
                    :fingerprint, :candidate_operation_id
                 )'
            );
            foreach ($rows as $row) {
                $insertRow->execute([
                    'batch_id' => $batchId,
                    'line_number' => $row['line_number'],
                    'transaction_date' => $row['transaction_date'],
                    'operation_type' => $row['operation_type'],
                    'amount' => $row['amount'],
                    'description' => $row['description'],
                    'fingerprint' => hash('sha256', implode('|', [
                        $accountId,
                        $row['line_number'],
                        $row['transaction_date'],
                        $row['operation_type'],
                        $row['amount'],
                        $row['description'],
                    ])),
                    'candidate_operation_id' => $row['candidate_operation_id'],
                ]);
            }
            $this->db->commit();

            return $batchId;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function confirm(int $userId, mixed $batchId, mixed $decisions): array
    {
        $id = filter_var($batchId, FILTER_VALIDATE_INT);
        if (!$id || $id < 1 || !is_array($decisions)) {
            throw new DomainException('La selección de conciliación no es válida.');
        }
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->db->beginTransaction();
        try {
            $batchStatement = $this->db->prepare(
                'SELECT b.id, b.account_id, b.status, a.household_id
                 FROM reconciliation_batches b
                 INNER JOIN accounts a ON a.id = b.account_id
                 WHERE b.id = :id AND b.created_by = :user_id
                 LIMIT 1 FOR UPDATE'
            );
            $batchStatement->execute(['id' => $id, 'user_id' => $userId]);
            $batch = $batchStatement->fetch();
            if (!$batch || $batch['status'] !== 'preview') {
                throw new DomainException('La conciliación no existe, ya fue procesada o no tienes acceso.');
            }

            $rowsStatement = $this->db->prepare(
                'SELECT id, candidate_operation_id, status
                 FROM reconciliation_rows
                 WHERE batch_id = :batch_id
                 ORDER BY id
                 FOR UPDATE'
            );
            $rowsStatement->execute(['batch_id' => $id]);
            $rows = $rowsStatement->fetchAll();
            if (count($decisions) !== count($rows)) {
                throw new DomainException('Las decisiones enviadas no coinciden con las filas del extracto.');
            }
            $update = $this->db->prepare(
                'UPDATE reconciliation_rows
                 SET status = :status, matched_operation_id = :operation_id
                 WHERE id = :id AND batch_id = :batch_id AND status = "pending"'
            );
            $matched = 0;
            $selectedOperations = [];
            foreach ($rows as $row) {
                $rowId = (string) $row['id'];
                if (!array_key_exists($rowId, $decisions)) {
                    throw new DomainException('Selecciona una decisión para cada fila del extracto.');
                }
                $decision = $decisions[$rowId];
                if (!in_array($decision, ['matched', 'unmatched', 'ignored'], true)) {
                    throw new DomainException('Una decisión de conciliación no es válida.');
                }
                $operationId = null;
                if ($decision === 'matched') {
                    if ($row['candidate_operation_id'] === null) {
                        throw new DomainException('Solo se pueden confirmar coincidencias exactas sugeridas.');
                    }
                    $operationId = (int) $row['candidate_operation_id'];
                    if (isset($selectedOperations[$operationId])) {
                        throw new DomainException('No puedes conciliar dos filas con el mismo movimiento.');
                    }
                    if (!$this->candidateStillMatches($operationId, (int) $batch['account_id'])) {
                        throw new DomainException('Una coincidencia cambió desde la vista previa. Genera una nueva conciliación.');
                    }
                    $selectedOperations[$operationId] = true;
                    $matched++;
                }
                $update->execute([
                    'status' => $decision,
                    'operation_id' => $operationId,
                    'id' => (int) $row['id'],
                    'batch_id' => (int) $id,
                ]);
            }
            $complete = $this->db->prepare(
                'UPDATE reconciliation_batches
                 SET status = "reconciled", completed_at = UTC_TIMESTAMP()
                 WHERE id = :id AND created_by = :user_id AND status = "preview"'
            );
            $complete->execute(['id' => $id, 'user_id' => $userId]);
            $audit = $this->db->prepare(
                'INSERT INTO audit_log (
                    actor_user_id, household_id, event_type, target_type, target_id
                 ) VALUES (
                    :user_id, :household_id,
                    "finance.reconciliation_confirmed", "reconciliation_batch", :batch_id
                 )'
            );
            $audit->execute([
                'user_id' => $userId,
                'household_id' => $batch['household_id'],
                'batch_id' => (int) $id,
            ]);
            $this->db->commit();

            return ['matched' => $matched, 'rows' => count($rows)];
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function parseRows($handle, string $delimiter, array $input, int $accountId): array
    {
        $headers = fgetcsv($handle, 0, $delimiter, '"', '\\');
        if (!is_array($headers) || count($headers) < 3) {
            throw new DomainException('La primera fila debe contener los encabezados CSV.');
        }
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        $indices = [];
        foreach ($headers as $index => $header) {
            $header = is_string($header) ? trim($header) : '';
            if ($header === '' || !mb_check_encoding($header, 'UTF-8')) {
                throw new DomainException('Los encabezados CSV deben estar completos y codificados en UTF-8.');
            }
            $normalized = mb_strtolower($header);
            if (isset($indices[$normalized])) {
                throw new DomainException('Los encabezados CSV están vacíos, repetidos o no usan UTF-8.');
            }
            $indices[$normalized] = $index;
        }
        $columns = [];
        foreach (['date_column' => 'fecha', 'amount_column' => 'valor', 'description_column' => 'descripción'] as $field => $label) {
            $header = $input[$field] ?? null;
            if (!is_string($header) || !mb_check_encoding($header, 'UTF-8')
                || !isset($indices[mb_strtolower(trim($header))])) {
                throw new DomainException('Selecciona una columna válida para ' . $label . '.');
            }
            $columns[$field] = $indices[mb_strtolower(trim($header))];
        }
        $directionHeader = trim((string) ($input['direction_column'] ?? ''));
        if (!mb_check_encoding($directionHeader, 'UTF-8')) {
            throw new DomainException('El encabezado de dirección no usa UTF-8.');
        }
        $columns['direction_column'] = $directionHeader === ''
            ? null
            : ($indices[mb_strtolower($directionHeader)] ?? null);
        if ($directionHeader !== '' && $columns['direction_column'] === null) {
            throw new DomainException('Selecciona una columna de dirección válida.');
        }
        if (count(array_unique(array_filter($columns, static fn ($index) => $index !== null))) !== count(array_filter($columns, static fn ($index) => $index !== null))) {
            throw new DomainException('Asigna cada dato a una columna diferente.');
        }

        $rows = [];
        $lineNumber = 1;
        while (($fields = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $lineNumber++;
            if ($lineNumber > 65535) {
                throw new DomainException('El archivo tiene demasiadas filas.');
            }
            if (count($fields) === 1 && trim((string) $fields[0]) === '') {
                continue;
            }
            if (count($fields) !== count($headers)) {
                throw new DomainException('La fila ' . $lineNumber . ' no tiene el mismo número de columnas que el encabezado.');
            }
            if (count($rows) >= self::MAX_ROWS) {
                throw new DomainException('El CSV no puede superar 1.000 filas.');
            }
            foreach ($fields as $field) {
                if (!is_string($field) || !mb_check_encoding($field, 'UTF-8')) {
                    throw new DomainException('El archivo CSV debe estar codificado en UTF-8.');
                }
            }
            $amountText = trim((string) $fields[$columns['amount_column']]);
            $sign = '';
            if (preg_match('/\A([+-])/', $amountText, $matches)) {
                $sign = $matches[1];
                $amountText = substr($amountText, 1);
            }
            $amount = CsvValueParser::normalizeAmount($amountText);
            $direction = $sign === ''
                ? $this->direction(
                    $columns['direction_column'] === null ? '' : (string) $fields[$columns['direction_column']]
                )
                : ($sign === '-' ? 'expense' : 'income');
            if ($sign !== '' && $columns['direction_column'] !== null) {
                $explicitDirection = $this->direction((string) $fields[$columns['direction_column']]);
                if ($explicitDirection !== $direction) {
                    throw new DomainException('El signo y la dirección de la fila ' . $lineNumber . ' se contradicen.');
                }
            }
            $description = trim((string) $fields[$columns['description_column']]);
            if ($description === '' || mb_strlen($description) > 255) {
                throw new DomainException('La descripción de la fila ' . $lineNumber . ' debe tener entre 1 y 255 caracteres.');
            }
            $date = CsvValueParser::normalizeDate($fields[$columns['date_column']]);
            $rows[] = [
                'line_number' => $lineNumber,
                'transaction_date' => $date,
                'operation_type' => $direction,
                'amount' => $amount,
                'description' => $description,
                'candidate_operation_id' => $this->findCandidate($accountId, $date, $direction, $amount),
            ];
        }

        return $rows;
    }

    private function direction(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        if (in_array($normalized, ['ingreso', 'abono', 'crédito', 'credito', 'credit', 'income', 'inflow', 'deposit'], true)) {
            return 'income';
        }
        if (in_array($normalized, ['gasto', 'cargo', 'débito', 'debito', 'debit', 'expense', 'outflow', 'withdrawal'], true)) {
            return 'expense';
        }

        throw new DomainException('Indica el tipo ingreso/gasto o utiliza importes con signo positivo/negativo.');
    }

    private function findCandidate(int $accountId, string $date, string $type, string $amount): ?int
    {
        $direction = $type === 'income' ? 'inflow' : 'outflow';
        $statement = $this->db->prepare(
            'SELECT o.id
             FROM operations o
             INNER JOIN movements m ON m.operation_id = o.id
             INNER JOIN accounts a ON a.id = m.account_id
             WHERE m.account_id = :account_id
               AND m.direction = :direction
               AND m.amount = :amount
               AND o.operation_type = :operation_type
               AND o.status = "confirmed"
               AND o.confirmed_at IS NOT NULL
               AND o.effective_date = :effective_date
               AND o.scope = a.scope
               AND o.owner_user_id <=> a.owner_user_id
               AND o.household_id <=> a.household_id
               AND NOT EXISTS (
                    SELECT 1 FROM reconciliation_rows rr
                    WHERE rr.matched_operation_id = o.id
               )
             ORDER BY o.id
             LIMIT 2'
        );
        $statement->execute([
            'account_id' => $accountId,
            'direction' => $direction,
            'amount' => $amount,
            'operation_type' => $type,
            'effective_date' => $date,
        ]);
        $matches = $statement->fetchAll(PDO::FETCH_COLUMN);

        return count($matches) === 1 ? (int) $matches[0] : null;
    }

    private function candidateStillMatches(int $operationId, int $accountId): bool
    {
        $statement = $this->db->prepare(
            'SELECT 1
             FROM operations o
             INNER JOIN movements m ON m.operation_id = o.id
             INNER JOIN reconciliation_rows rr ON rr.candidate_operation_id = o.id
             WHERE o.id = :operation_id
               AND m.account_id = :account_id
               AND o.operation_type = rr.operation_type
               AND o.effective_date = rr.transaction_date
               AND m.amount = rr.amount
               AND m.direction = CASE WHEN o.operation_type = "income" THEN "inflow" ELSE "outflow" END
               AND rr.status = "pending"
               AND rr.matched_operation_id IS NULL
               AND o.status = "confirmed"
               AND o.confirmed_at IS NOT NULL
               AND NOT EXISTS (
                    SELECT 1 FROM reconciliation_rows matched
                    WHERE matched.matched_operation_id = o.id
               )
             LIMIT 1'
        );
        $statement->execute(['operation_id' => $operationId, 'account_id' => $accountId]);

        return $statement->fetchColumn() !== false;
    }
}
