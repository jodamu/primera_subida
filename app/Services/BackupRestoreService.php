<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DomainException;
use PDO;
use Throwable;

final class BackupRestoreService
{
    private const MAX_FILE_BYTES = 8388608;
    private const MAX_ROWS = 5000;

    public function __construct(
        private readonly PDO $db,
        private readonly HouseholdService $households,
    ) {
    }

    private function restoreKey(string $type, string $origin, string $sourceId, array $space): string
    {
        return hash(
            'sha256',
            implode('|', ['restore', $origin, $type, $sourceId, $space['scope'], $space['space_id']])
        );
    }

    public function createPreview(int $userId, mixed $file): int
    {
        if (!is_array($file)) {
            throw new DomainException('Selecciona un archivo JSON de respaldo.');
        }
        $temporaryPath = $file['tmp_name'] ?? null;
        $filename = $file['name'] ?? null;
        if (
            ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_string($temporaryPath)
            || !is_uploaded_file($temporaryPath)
            || !is_string($filename)
            || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'json'
        ) {
            throw new DomainException('Selecciona un archivo JSON de respaldo válido.');
        }
        $fileSize = filesize($temporaryPath);
        if ($fileSize === false || $fileSize < 1 || $fileSize > self::MAX_FILE_BYTES) {
            throw new DomainException('El respaldo debe pesar entre 1 byte y 8 MB.');
        }
        $contents = file_get_contents($temporaryPath);
        if (!is_string($contents)) {
            throw new DomainException('No fue posible leer el archivo de respaldo.');
        }
        try {
            $backup = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new DomainException('El archivo no contiene un JSON de respaldo válido.', 0, $exception);
        }
        $data = $this->validatedData($backup);
        $origin = $backup['source_origin_id'] ?? substr(hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)), 0, 32);
        if (!is_string($origin) || !preg_match('/\A[a-f0-9]{32}\z/', $origin)) {
            throw new DomainException('El identificador de origen del respaldo no es válido.');
        }
        $fingerprint = hash('sha256', $contents);
        $rowCount = array_sum(array_map('count', $data));

        $expire = $this->db->prepare(
            'DELETE FROM backup_restore_batches
             WHERE created_by = :user_id AND status = "preview"
               AND created_at < UTC_TIMESTAMP() - INTERVAL 1 DAY'
        );
        $expire->execute(['user_id' => $userId]);
        $statement = $this->db->prepare(
            'INSERT INTO backup_restore_batches (
                created_by, fingerprint, source_version, payload_json, row_count
             ) VALUES (
                :created_by, :fingerprint, :source_version, :payload_json, :row_count
             )
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $statement->execute([
            'created_by' => $userId,
            'fingerprint' => $fingerprint,
            'source_version' => (int) ($backup['format_version'] ?? 1),
            'payload_json' => $contents,
            'row_count' => $rowCount,
        ]);

        $batchId = (int) $this->db->lastInsertId();
        $status = $this->db->prepare(
            'SELECT status FROM backup_restore_batches WHERE id = :id AND created_by = :user_id'
        );
        $status->execute(['id' => $batchId, 'user_id' => $userId]);
        if ($status->fetchColumn() !== 'preview') {
            throw new DomainException('Este archivo de respaldo ya fue procesado. Carga una exportación nueva si necesitas otra restauración.');
        }

        return $batchId;
    }

    public function previewForUser(int $userId, mixed $batchId): array
    {
        $batch = $this->batchForUser($userId, $batchId);
        if ($batch['status'] !== 'preview') {
            return ['batch' => $batch, 'source_spaces' => [], 'counts' => []];
        }
        $backup = $this->decodePayload($batch['payload_json']);
        $data = $this->validatedData($backup);
        $spaces = $this->sourceSpaces($backup, $data);

        return [
            'batch' => $batch,
            'source_spaces' => array_values(array_filter(
                $spaces,
                static fn (array $space): bool => ($space['scope'] ?? null) === 'household'
            )),
            'counts' => array_map('count', $data),
        ];
    }

    public function recentForUser(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, status, row_count, source_version, created_at, completed_at
             FROM backup_restore_batches
             WHERE created_by = :user_id
             ORDER BY id DESC
             LIMIT 20'
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll();
    }

    public function restore(int $userId, mixed $batchId, mixed $spaceMapping): array
    {
        if (!is_array($spaceMapping)) {
            throw new DomainException('Selecciona el destino para cada hogar del respaldo.');
        }
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->db->beginTransaction();
        try {
            $batch = $this->batchForUser($userId, $batchId, true);
            if ($batch['status'] !== 'preview') {
                throw new DomainException('Este respaldo ya fue procesado o cancelado.');
            }
            $backup = $this->decodePayload($batch['payload_json']);
            $data = $this->validatedData($backup);
            $origin = $backup['source_origin_id'] ?? substr(hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)), 0, 32);
            $sourceSpaces = array_values(array_filter(
                $this->sourceSpaces($backup, $data),
                static fn (array $space): bool => ($space['scope'] ?? null) === 'household'
            ));
            $mapping = $this->validateSpaceMapping($userId, $sourceSpaces, $spaceMapping);
            $counts = $this->mergeData($userId, (string) $origin, $data, $mapping);
            $audit = $this->db->prepare(
                'INSERT INTO audit_log (
                    actor_user_id, household_id, event_type, target_type, target_id
                 ) VALUES (
                    :user_id, :household_id, "finance.backup_restored",
                    "backup_restore_batch", :batch_id
                 )'
            );
            $auditTargets = [];
            foreach ($mapping as $targetSpace) {
                if ($targetSpace !== null && $targetSpace['scope'] === 'household') {
                    $auditTargets[(string) $targetSpace['space_id']] = (int) $targetSpace['space_id'];
                }
            }
            if (!$auditTargets) {
                $auditTargets[null] = null;
            }
            foreach ($auditTargets as $householdId) {
                $audit->execute([
                    'user_id' => $userId,
                    'household_id' => $householdId,
                    'batch_id' => (int) $batch['id'],
                ]);
            }
            $complete = $this->db->prepare(
                'UPDATE backup_restore_batches
                 SET status = "restored", payload_json = NULL, completed_at = UTC_TIMESTAMP()
                 WHERE id = :id AND created_by = :user_id AND status = "preview"'
            );
            $complete->execute(['id' => (int) $batch['id'], 'user_id' => $userId]);
            $this->db->commit();

            return $counts;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function validatedData(mixed $backup): array
    {
        if (
            !is_array($backup)
            || !in_array((int) ($backup['format_version'] ?? 1), [1, 2], true)
            || !is_array($backup['data'] ?? null)
        ) {
            throw new DomainException('El archivo no es un respaldo compatible de Mi Finanzas.');
        }
        $allowedTables = [
            'account_types', 'categories', 'accounts', 'operations', 'movements', 'transfers',
            'liabilities', 'liability_movements', 'financial_space_settings', 'budgets',
            'savings_goals', 'savings_goal_entries', 'recurring_movements',
            'recurring_movement_occurrences',
        ];
        $data = [];
        $totalRows = 0;
        foreach ($allowedTables as $table) {
            $rows = $backup['data'][$table] ?? [];
            if (!is_array($rows) || array_filter($rows, static fn ($row): bool => !is_array($row))) {
                throw new DomainException('Una tabla del respaldo tiene una estructura inválida: ' . $table . '.');
            }
            $data[$table] = array_values($rows);
            $totalRows += count($rows);
        }
        if ($totalRows > self::MAX_ROWS) {
            throw new DomainException('El respaldo supera el límite de 5.000 registros.');
        }
        if ((int) ($backup['format_version'] ?? 1) >= 2 && !is_array($backup['financial_spaces'] ?? null)) {
            throw new DomainException('El respaldo no contiene la lista de espacios financieros.');
        }
        if (is_array($backup['financial_spaces'] ?? null)) {
            $sourceHouseholds = [];
            foreach ($backup['financial_spaces'] as $space) {
                if (!is_array($space) || !in_array($space['scope'] ?? null, ['personal', 'household'], true)) {
                    throw new DomainException('Un espacio financiero del respaldo tiene una estructura inválida.');
                }
                if ($space['scope'] === 'household') {
                    $sourceId = filter_var($space['source_id'] ?? null, FILTER_VALIDATE_INT);
                    if (!$sourceId || $sourceId < 1 || isset($sourceHouseholds[(string) $sourceId]) || !is_string($space['name'] ?? null)) {
                        throw new DomainException('Un hogar del respaldo tiene un identificador o nombre inválido.');
                    }
                    $sourceHouseholds[(string) $sourceId] = true;
                }
            }
        }

        return $data;
    }

    private function validateSpaceMapping(int $userId, array $sourceSpaces, array $requested): array
    {
        $available = [];
        foreach ($this->households->forUser($userId) as $household) {
            if (in_array($household['role'], ['owner', 'admin'], true)) {
                $available[(string) $household['id']] = (int) $household['id'];
            }
        }
        $mapping = [];
        foreach ($sourceSpaces as $space) {
            $sourceId = filter_var($space['source_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$sourceId || $sourceId < 1 || !array_key_exists((string) $sourceId, $requested)) {
                throw new DomainException('Falta seleccionar un destino para un hogar del respaldo.');
            }
            $target = $requested[(string) $sourceId];
            if (!is_string($target) && !is_int($target)) {
                throw new DomainException('Selecciona un destino de restauración válido.');
            }
            if ($target === 'skip') {
                $mapping[(string) $sourceId] = null;
            } elseif ($target === 'personal') {
                $mapping[(string) $sourceId] = ['scope' => 'personal', 'space_id' => $userId];
            } else {
                $targetId = filter_var($target, FILTER_VALIDATE_INT);
                if (!$targetId || !isset($available[(string) $targetId])) {
                    throw new DomainException('Solo puedes restaurar en un hogar propio o administrado.');
                }
                $mapping[(string) $sourceId] = ['scope' => 'household', 'space_id' => (int) $targetId];
            }
        }
        if (count($requested) !== count($sourceSpaces)) {
            throw new DomainException('El formulario incluye destinos que no pertenecen a los hogares del respaldo.');
        }

        return $mapping;
    }

    private function sourceSpaces(array $backup, array $data): array
    {
        if (is_array($backup['financial_spaces'] ?? null)) {
            return $backup['financial_spaces'];
        }
        $ids = [];
        foreach ([
            'accounts', 'operations', 'liabilities', 'financial_space_settings', 'budgets',
            'savings_goals', 'recurring_movements',
        ] as $table) {
            foreach ($data[$table] as $row) {
                if (($row['scope'] ?? null) === 'household' && isset($row['household_id'])) {
                    $ids[(string) $row['household_id']] = (int) $row['household_id'];
                }
            }
        }
        $spaces = [];
        foreach ($ids as $id) {
            $spaces[] = [
                'scope' => 'household',
                'source_id' => $id,
                'name' => 'Hogar de origen #' . $id . ' (respaldo anterior)',
            ];
        }

        return $spaces;
    }

    private function batchForUser(int $userId, mixed $batchId, bool $lock = false): array
    {
        $id = filter_var($batchId, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            throw new DomainException('Selecciona un respaldo válido.');
        }
        $sql = 'SELECT id, status, source_version, payload_json, row_count, created_at
                FROM backup_restore_batches
                WHERE id = :id AND created_by = :user_id
                LIMIT 1';
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->db->prepare($sql);
        $statement->execute(['id' => $id, 'user_id' => $userId]);
        $batch = $statement->fetch();
        if (!$batch) {
            throw new DomainException('El respaldo no existe o no tienes acceso.');
        }

        return $batch;
    }

    private function decodePayload(?string $payload): array
    {
        if (!is_string($payload) || $payload === '') {
            throw new DomainException('El archivo temporal de respaldo ya no está disponible. Carga el archivo de nuevo.');
        }
        try {
            $backup = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new DomainException('El respaldo guardado no es válido.', 0, $exception);
        }

        return is_array($backup) ? $backup : throw new DomainException('El respaldo guardado no es válido.');
    }

    private function mergeData(int $userId, string $origin, array $data, array $spaceMapping): array
    {
        $counts = [];
        foreach ($data as $table => $rows) {
            $indexed = [];
            foreach ($rows as $row) {
                if (isset($row['id'])) {
                    $indexed[(string) $row['id']] = $row;
                }
            }
            if (count($indexed) === count($rows)) {
                $data[$table] = $indexed;
            }
        }
        $accountTypes = [];
        foreach ($this->db->query('SELECT id, code FROM account_types')->fetchAll() as $row) {
            $accountTypes[(string) $row['code']] = (int) $row['id'];
        }
        $sourceAccountTypes = [];
        foreach ($data['account_types'] as $row) {
            $targetTypeId = $accountTypes[(string) $row['code']] ?? null;
            if ($targetTypeId !== null) {
                $sourceAccountTypes[(string) $row['id']] = $targetTypeId;
            }
        }
        $categories = [];
        foreach ($this->db->query('SELECT id, code, kind FROM categories')->fetchAll() as $row) {
            $categories[$row['kind'] . '|' . $row['code']] = (int) $row['id'];
        }
        foreach ($data['categories'] as $row) {
            if (($row['scope'] ?? null) === null) {
                continue;
            }
            if (
                $row['scope'] === 'household'
                && !array_key_exists((string) ($row['household_id'] ?? ''), $spaceMapping)
            ) {
                continue;
            }
            $space = $this->mappedSpace($row, $userId, $spaceMapping);
            $isPersonal = $space['scope'] === 'personal';
            $lookup = $this->db->prepare(
                'SELECT id FROM categories
                 WHERE scope = :scope AND kind = :kind AND name = :name
                   AND (owner_user_id <=> :owner_user_id) AND (household_id <=> :household_id)
                 LIMIT 1'
            );
            $lookup->execute([
                'scope' => $space['scope'],
                'kind' => $row['kind'],
                'name' => $row['name'],
                'owner_user_id' => $isPersonal ? (int) $space['space_id'] : null,
                'household_id' => $isPersonal ? null : (int) $space['space_id'],
            ]);
            $existingCategoryId = $lookup->fetchColumn();
            if ($existingCategoryId === false) {
                $insert = $this->db->prepare(
                    'INSERT INTO categories (
                        code, name, kind, scope, owner_user_id, household_id, created_by, sort_order
                     ) VALUES (
                        :code, :name, :kind, :scope, :owner_user_id, :household_id, :created_by, 100
                     )'
                );
                $insert->execute([
                    'code' => 'c' . bin2hex(random_bytes(12)),
                    'name' => $row['name'],
                    'kind' => $row['kind'],
                    'scope' => $space['scope'],
                    'owner_user_id' => $isPersonal ? (int) $space['space_id'] : null,
                    'household_id' => $isPersonal ? null : (int) $space['space_id'],
                    'created_by' => $userId,
                ]);
                $existingCategoryId = (int) $this->db->lastInsertId();
            }
            $categories[$row['kind'] . '|' . $row['code']] = (int) $existingCategoryId;
        }
        $accounts = [];
        foreach ($data['accounts'] as $row) {
            $space = $this->mappedSpace($row, $userId, $spaceMapping);
            if ($space === null) {
                $this->count($counts, 'accounts', 'skipped');
                continue;
            }
            $typeId = $sourceAccountTypes[(string) $this->required($row, 'account_type_id', 'account')] ?? null;
            if ($typeId === null) {
                throw new DomainException('El respaldo contiene un tipo de cuenta que no existe en el sistema.');
            }
            $targetId = $this->restoredId($userId, $origin, 'account', $row, $space);
            if ($targetId === null) {
                $lookup = $this->db->prepare(
                    'SELECT id FROM accounts
                     WHERE scope = :scope
                       AND owner_user_id <=> :owner_user_id
                       AND household_id <=> :household_id
                       AND account_type_id = :account_type_id
                       AND name = :name
                       AND initial_balance = :initial_balance
                       AND initial_balance_date = :initial_balance_date
                       AND currency = :currency
                       AND overdraft_enabled = :overdraft_enabled
                       AND overdraft_limit = :overdraft_limit
                       AND status = :status
                     LIMIT 1'
                );
                $lookup->execute([
                    'scope' => $space['scope'],
                    'owner_user_id' => $space['scope'] === 'personal' ? $space['space_id'] : null,
                    'household_id' => $space['scope'] === 'household' ? $space['space_id'] : null,
                    'account_type_id' => $typeId,
                    'name' => $row['name'],
                    'initial_balance' => $row['initial_balance'],
                    'initial_balance_date' => $row['initial_balance_date'],
                    'currency' => $row['currency'],
                    'overdraft_enabled' => $row['overdraft_enabled'],
                    'overdraft_limit' => $row['overdraft_limit'],
                    'status' => $row['status'],
                ]);
                $targetId = $lookup->fetchColumn();
            }
            if (!$targetId) {
                $insert = $this->db->prepare(
                    'INSERT INTO accounts (
                        account_type_id, name, scope, owner_user_id, household_id, created_by,
                        initial_balance, initial_balance_date, currency, overdraft_enabled,
                        overdraft_limit, status, created_at, archived_at
                     ) VALUES (
                        :account_type_id, :name, :scope, :owner_user_id, :household_id, :created_by,
                        :initial_balance, :initial_balance_date, :currency, :overdraft_enabled,
                        :overdraft_limit, :status, :created_at, :archived_at
                     )'
                );
                $insert->execute([
                    'account_type_id' => $typeId,
                    'name' => $row['name'],
                    'scope' => $space['scope'],
                    'owner_user_id' => $space['scope'] === 'personal' ? $space['space_id'] : null,
                    'household_id' => $space['scope'] === 'household' ? $space['space_id'] : null,
                    'created_by' => $userId,
                    'initial_balance' => $row['initial_balance'],
                    'initial_balance_date' => $row['initial_balance_date'],
                    'currency' => $row['currency'],
                    'overdraft_enabled' => $row['overdraft_enabled'],
                    'overdraft_limit' => $row['overdraft_limit'],
                    'status' => $row['status'],
                    'created_at' => $row['created_at'],
                    'archived_at' => $row['archived_at'],
                ]);
                $targetId = (int) $this->db->lastInsertId();
                $this->count($counts, 'accounts', 'added');
            } else {
                $targetId = (int) $targetId;
                $this->count($counts, 'accounts', 'already_present');
            }
            $accounts[(string) $row['id']] = $targetId;
            $this->remember($userId, $origin, 'account', $row, $space, $targetId);
        }

        $liabilities = [];
        foreach ($data['liabilities'] as $row) {
            $space = $this->mappedSpace($row, $userId, $spaceMapping);
            if ($space === null) {
                $this->count($counts, 'liabilities', 'skipped');
                continue;
            }
            $targetId = $this->restoredId($userId, $origin, 'liability', $row, $space);
            if ($targetId === null) {
                $lookup = $this->db->prepare(
                    'SELECT id FROM liabilities
                     WHERE scope = :scope
                       AND owner_user_id <=> :owner_user_id
                       AND household_id <=> :household_id
                       AND name = :name
                       AND kind = :kind
                       AND creditor <=> :creditor
                       AND initial_balance = :initial_balance
                       AND initial_balance_date = :initial_balance_date
                       AND credit_limit <=> :credit_limit
                       AND statement_close_day <=> :statement_close_day
                       AND payment_due_day <=> :payment_due_day
                       AND annual_interest_rate <=> :annual_interest_rate
                       AND planned_monthly_payment <=> :planned_monthly_payment
                       AND currency = :currency
                       AND status = :status
                     LIMIT 1'
                );
                $lookup->execute([
                    'scope' => $space['scope'],
                    'owner_user_id' => $space['scope'] === 'personal' ? $space['space_id'] : null,
                    'household_id' => $space['scope'] === 'household' ? $space['space_id'] : null,
                    'name' => $row['name'],
                    'kind' => $row['kind'],
                    'creditor' => $row['creditor'],
                    'initial_balance' => $row['initial_balance'],
                    'initial_balance_date' => $row['initial_balance_date'],
                    'credit_limit' => $row['credit_limit'],
                    'statement_close_day' => $row['statement_close_day'],
                    'payment_due_day' => $row['payment_due_day'],
                    'annual_interest_rate' => $row['annual_interest_rate'],
                    'planned_monthly_payment' => $row['planned_monthly_payment'] ?? null,
                    'currency' => $row['currency'],
                    'status' => $row['status'],
                ]);
                $targetId = $lookup->fetchColumn();
            }
            if (!$targetId) {
                $insert = $this->db->prepare(
                    'INSERT INTO liabilities (
                        kind, name, creditor, scope, owner_user_id, household_id, created_by,
                        initial_balance, initial_balance_date, credit_limit, statement_close_day,
                        payment_due_day, annual_interest_rate, planned_monthly_payment, currency,
                        status, created_at, archived_at
                     ) VALUES (
                        :kind, :name, :creditor, :scope, :owner_user_id, :household_id, :created_by,
                        :initial_balance, :initial_balance_date, :credit_limit, :statement_close_day,
                        :payment_due_day, :annual_interest_rate, :planned_monthly_payment, :currency,
                        :status, :created_at, :archived_at
                     )'
                );
                $insert->execute([
                    'kind' => $row['kind'],
                    'name' => $row['name'],
                    'creditor' => $row['creditor'],
                    'scope' => $space['scope'],
                    'owner_user_id' => $space['scope'] === 'personal' ? $space['space_id'] : null,
                    'household_id' => $space['scope'] === 'household' ? $space['space_id'] : null,
                    'created_by' => $userId,
                    'initial_balance' => $row['initial_balance'],
                    'initial_balance_date' => $row['initial_balance_date'],
                    'credit_limit' => $row['credit_limit'],
                    'statement_close_day' => $row['statement_close_day'],
                    'payment_due_day' => $row['payment_due_day'],
                    'annual_interest_rate' => $row['annual_interest_rate'],
                    'planned_monthly_payment' => $row['planned_monthly_payment'] ?? null,
                    'currency' => $row['currency'],
                    'status' => $row['status'],
                    'created_at' => $row['created_at'],
                    'archived_at' => $row['archived_at'],
                ]);
                $targetId = (int) $this->db->lastInsertId();
                $this->count($counts, 'liabilities', 'added');
            } else {
                $targetId = (int) $targetId;
                $this->count($counts, 'liabilities', 'already_present');
            }
            $liabilities[(string) $row['id']] = $targetId;
            $this->remember($userId, $origin, 'liability', $row, $space, $targetId);
        }

        $operations = $data['operations'];
        usort($operations, static fn (array $left, array $right): int =>
            (int) ($left['reversal_of_operation_id'] !== null) <=> (int) ($right['reversal_of_operation_id'] !== null)
        );
        $operationMap = [];
        foreach ($operations as $row) {
            $space = $this->mappedSpace($row, $userId, $spaceMapping);
            if ($space === null) {
                $this->count($counts, 'operations', 'skipped');
                continue;
            }
            $sourceId = (string) $row['id'];
            $targetId = $this->restoredId($userId, $origin, 'operation', $row, $space);
            $categoryId = null;
            if ($row['category_id'] !== null) {
                $sourceCategory = $this->sourceRowById($data['categories'], $row['category_id']);
                $categoryId = $categories[$sourceCategory['kind'] . '|' . $sourceCategory['code']] ?? null;
                if ($categoryId === null) {
                    throw new DomainException('Una categoría del respaldo no existe en este sistema.');
                }
            }
            $reversalOf = null;
            if ($row['reversal_of_operation_id'] !== null) {
                $reversalOf = $operationMap[(string) $row['reversal_of_operation_id']] ?? null;
                if ($reversalOf === null) {
                    throw new DomainException('El respaldo contiene una anulación sin su operación original.');
                }
            }
            if ($targetId === null) {
                $lookup = $this->db->prepare(
                    'SELECT id FROM operations
                     WHERE operation_type = :operation_type
                       AND status = :status
                       AND scope = :scope
                       AND owner_user_id <=> :owner_user_id
                       AND household_id <=> :household_id
                       AND category_id <=> :category_id
                       AND description = :description
                       AND effective_date = :effective_date
                       AND reversal_of_operation_id <=> :reversal_of_operation_id
                       AND created_at = :created_at
                       AND confirmed_at <=> :confirmed_at
                     LIMIT 1'
                );
                $lookup->execute([
                    'operation_type' => $row['operation_type'],
                    'status' => $row['status'],
                    'scope' => $space['scope'],
                    'owner_user_id' => $space['scope'] === 'personal' ? $space['space_id'] : null,
                    'household_id' => $space['scope'] === 'household' ? $space['space_id'] : null,
                    'category_id' => $categoryId,
                    'description' => $row['description'],
                    'effective_date' => $row['effective_date'],
                    'reversal_of_operation_id' => $reversalOf,
                    'created_at' => $row['created_at'],
                    'confirmed_at' => $row['confirmed_at'],
                ]);
                $targetId = $lookup->fetchColumn();
            }
            if (!$targetId) {
                $key = $this->restoreKey('operation', $origin, $sourceId, $space);
                $lookup = $this->db->prepare(
                    'SELECT id FROM operations WHERE created_by = :created_by AND idempotency_key = :idempotency_key LIMIT 1'
                );
                $lookup->execute(['created_by' => $userId, 'idempotency_key' => $key]);
                $targetId = $lookup->fetchColumn();
            }
            if (!$targetId) {
                $insert = $this->db->prepare(
                    'INSERT INTO operations (
                        operation_type, status, scope, owner_user_id, household_id, created_by,
                        category_id, description, effective_date, idempotency_key, import_fingerprint,
                        reversal_of_operation_id, created_at, confirmed_at
                     ) VALUES (
                        :operation_type, :status, :scope, :owner_user_id, :household_id, :created_by,
                        :category_id, :description, :effective_date, :idempotency_key, NULL,
                        :reversal_of_operation_id, :created_at, :confirmed_at
                     )'
                );
                $insert->execute([
                    'operation_type' => $row['operation_type'],
                    'status' => $row['status'],
                    'scope' => $space['scope'],
                    'owner_user_id' => $space['scope'] === 'personal' ? $space['space_id'] : null,
                    'household_id' => $space['scope'] === 'household' ? $space['space_id'] : null,
                    'created_by' => $userId,
                    'category_id' => $categoryId,
                    'description' => $row['description'],
                    'effective_date' => $row['effective_date'],
                    'idempotency_key' => $this->restoreKey('operation', $origin, $sourceId, $space),
                    'reversal_of_operation_id' => $reversalOf,
                    'created_at' => $row['created_at'],
                    'confirmed_at' => $row['confirmed_at'],
                ]);
                $targetId = (int) $this->db->lastInsertId();
                $this->count($counts, 'operations', 'added');
            } else {
                $targetId = (int) $targetId;
                $this->count($counts, 'operations', 'already_present');
            }
            $operationMap[$sourceId] = (int) $targetId;
            $this->remember($userId, $origin, 'operation', $row, $space, (int) $targetId);
        }

        $movementMap = [];
        foreach ($data['movements'] as $row) {
            $operationId = $operationMap[(string) $row['operation_id']] ?? null;
            $accountId = $accounts[(string) $row['account_id']] ?? null;
            if ($operationId === null || $accountId === null) {
                $this->count($counts, 'movements', 'skipped');
                continue;
            }
            $space = $this->mappedSpace(
                $this->sourceRowById($data['operations'], $row['operation_id']),
                $userId,
                $spaceMapping
            );
            $targetId = $this->restoredId($userId, $origin, 'movement', $row, $space);
            if ($targetId === null) {
                $lookup = $this->db->prepare(
                    'SELECT id, direction, amount FROM movements
                     WHERE operation_id = :operation_id AND account_id = :account_id LIMIT 1'
                );
                $lookup->execute(['operation_id' => $operationId, 'account_id' => $accountId]);
                $existing = $lookup->fetch();
                if ($existing) {
                    if ($existing['direction'] !== $row['direction'] || (string) $existing['amount'] !== (string) $row['amount']) {
                        throw new DomainException('Un movimiento existente no coincide con el respaldo; no se modificó ningún dato.');
                    }
                    $targetId = (int) $existing['id'];
                }
            }
            if (!$targetId) {
                $insert = $this->db->prepare(
                    'INSERT INTO movements (operation_id, account_id, direction, amount, created_at)
                     VALUES (:operation_id, :account_id, :direction, :amount, :created_at)'
                );
                $insert->execute([
                    'operation_id' => $operationId,
                    'account_id' => $accountId,
                    'direction' => $row['direction'],
                    'amount' => $row['amount'],
                    'created_at' => $row['created_at'],
                ]);
                $targetId = (int) $this->db->lastInsertId();
                $this->count($counts, 'movements', 'added');
            } else {
                $targetId = (int) $targetId;
                $this->count($counts, 'movements', 'already_present');
            }
            $movementMap[(string) $row['id']] = $targetId;
            $this->remember($userId, $origin, 'movement', $row, $space, $targetId);
        }

        foreach ($data['transfers'] as $row) {
            $operationId = $operationMap[(string) $row['operation_id']] ?? null;
            $sourceMovementId = $movementMap[(string) $row['source_movement_id']] ?? null;
            $destinationMovementId = $movementMap[(string) $row['destination_movement_id']] ?? null;
            if ($operationId === null || $sourceMovementId === null || $destinationMovementId === null) {
                $this->count($counts, 'transfers', 'skipped');
                continue;
            }
            $lookup = $this->db->prepare(
                'SELECT source_movement_id, destination_movement_id FROM transfers WHERE operation_id = :id LIMIT 1'
            );
            $lookup->execute(['id' => $operationId]);
            $existing = $lookup->fetch();
            if ($existing) {
                if ((int) $existing['source_movement_id'] !== $sourceMovementId
                    || (int) $existing['destination_movement_id'] !== $destinationMovementId) {
                    throw new DomainException('Una transferencia existente no coincide con el respaldo; no se modificó ningún dato.');
                }
                $this->count($counts, 'transfers', 'already_present');
                continue;
            }
            $insert = $this->db->prepare(
                'INSERT INTO transfers (operation_id, source_movement_id, destination_movement_id)
                 VALUES (:operation_id, :source_movement_id, :destination_movement_id)'
            );
            $insert->execute([
                'operation_id' => $operationId,
                'source_movement_id' => $sourceMovementId,
                'destination_movement_id' => $destinationMovementId,
            ]);
            $this->count($counts, 'transfers', 'added');
        }

        $liabilityMovementMap = [];
        foreach ($data['liability_movements'] as $row) {
            $liabilityId = $liabilities[(string) $row['liability_id']] ?? null;
            $operationId = $operationMap[(string) $row['operation_id']] ?? null;
            if ($liabilityId === null || $operationId === null) {
                $this->count($counts, 'liability_movements', 'skipped');
                continue;
            }
            $space = $this->mappedSpace(
                $this->sourceRowById($data['liabilities'], $row['liability_id']),
                $userId,
                $spaceMapping
            );
            $targetId = $this->restoredId($userId, $origin, 'liability_movement', $row, $space);
            if ($targetId === null) {
                $lookup = $this->db->prepare(
                    'SELECT id, direction, amount, interest_amount FROM liability_movements
                     WHERE operation_id = :operation_id AND liability_id = :liability_id LIMIT 1'
                );
                $lookup->execute(['operation_id' => $operationId, 'liability_id' => $liabilityId]);
                $existing = $lookup->fetch();
                if ($existing) {
                    if ($existing['direction'] !== $row['direction']
                        || (string) $existing['amount'] !== (string) $row['amount']
                        || (string) $existing['interest_amount'] !== (string) $row['interest_amount']) {
                        throw new DomainException('Un movimiento de deuda existente no coincide con el respaldo.');
                    }
                    $targetId = (int) $existing['id'];
                }
            }
            if (!$targetId) {
                $insert = $this->db->prepare(
                    'INSERT INTO liability_movements (
                        liability_id, operation_id, direction, amount, interest_amount, created_at
                     ) VALUES (
                        :liability_id, :operation_id, :direction, :amount, :interest_amount, :created_at
                     )'
                );
                $insert->execute([
                    'liability_id' => $liabilityId,
                    'operation_id' => $operationId,
                    'direction' => $row['direction'],
                    'amount' => $row['amount'],
                    'interest_amount' => $row['interest_amount'],
                    'created_at' => $row['created_at'],
                ]);
                $targetId = (int) $this->db->lastInsertId();
                $this->count($counts, 'liability_movements', 'added');
            } else {
                $targetId = (int) $targetId;
                $this->count($counts, 'liability_movements', 'already_present');
            }
            $liabilityMovementMap[(string) $row['id']] = $targetId;
            $this->remember($userId, $origin, 'liability_movement', $row, $space, $targetId);
        }

        $this->restoreSettings($userId, $data, $spaceMapping, $counts);
        $this->restoreBudgets($userId, $data, $spaceMapping, $categories, $counts);
        $goalMap = $this->restoreGoals($userId, $origin, $data, $spaceMapping, $counts);
        $this->restoreGoalEntries($userId, $origin, $data, $spaceMapping, $goalMap, $counts);
        $scheduleMap = $this->restoreSchedules($userId, $origin, $data, $spaceMapping, $accounts, $categories, $counts);
        $this->restoreOccurrences($userId, $origin, $data, $spaceMapping, $scheduleMap, $operationMap, $counts);

        return $counts;
    }

    private function mappedSpace(array $row, int $userId, array $mapping): ?array
    {
        if (($row['scope'] ?? null) === 'personal') {
            return ['scope' => 'personal', 'space_id' => $userId];
        }
        if (($row['scope'] ?? null) !== 'household') {
            throw new DomainException('Un registro del respaldo tiene un espacio financiero inválido.');
        }
        $sourceHousehold = (string) ($row['household_id'] ?? '');
        if (!array_key_exists($sourceHousehold, $mapping)) {
            throw new DomainException('No se asignó destino a un hogar incluido en los datos del respaldo.');
        }

        return $mapping[$sourceHousehold];
    }

    private function restoredId(int $userId, string $origin, string $type, array $row, array $space): ?int
    {
        $statement = $this->db->prepare(
            'SELECT target_id FROM backup_restore_records
             WHERE restored_by = :user_id AND source_origin_id = :origin
               AND record_type = :record_type AND source_id = :source_id
               AND target_scope = :scope AND target_space_id = :space_id
             LIMIT 1'
        );
        $statement->execute([
            'user_id' => $userId,
            'origin' => $origin,
            'record_type' => $type,
            'source_id' => (int) $row['id'],
            'scope' => $space['scope'],
            'space_id' => $space['space_id'],
        ]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function remember(int $userId, string $origin, string $type, array $row, array $space, int $targetId): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO backup_restore_records (
                restored_by, source_origin_id, record_type, source_id,
                target_scope, target_space_id, target_id
             ) VALUES (
                :user_id, :origin, :record_type, :source_id, :scope, :space_id, :target_id
             )
             ON DUPLICATE KEY UPDATE target_id = target_id'
        );
        $statement->execute([
            'user_id' => $userId,
            'origin' => $origin,
            'record_type' => $type,
            'source_id' => (int) $row['id'],
            'scope' => $space['scope'],
            'space_id' => $space['space_id'],
            'target_id' => $targetId,
        ]);
    }

    private function count(array &$counts, string $table, string $result): void
    {
        $counts[$table] ??= ['added' => 0, 'already_present' => 0, 'skipped' => 0];
        $counts[$table][$result]++;
    }

    private function required(array $row, string $column, string $table): mixed
    {
        if (!array_key_exists($column, $row)) {
            throw new DomainException('Falta el campo ' . $column . ' en la tabla ' . $table . ' del respaldo.');
        }

        return $row[$column];
    }

    private function sourceRowById(array $rows, mixed $id): array
    {
        if (isset($rows[(string) $id])) {
            return $rows[(string) $id];
        }
        foreach ($rows as $row) {
            if ((string) ($row['id'] ?? '') === (string) $id) {
                return $row;
            }
        }
        throw new DomainException('El respaldo contiene una referencia a un registro que no existe.');
    }

    private function restoreSettings(int $userId, array $data, array $mapping, array &$counts): void
    {
        foreach ($data['financial_space_settings'] as $row) {
            $space = $this->mappedSpace($row, $userId, $mapping);
            if ($space === null) {
                $this->count($counts, 'financial_space_settings', 'skipped');
                continue;
            }
            $params = [
                'scope' => $space['scope'],
                'owner_id' => $space['scope'] === 'personal' ? $space['space_id'] : null,
                'household_id' => $space['scope'] === 'household' ? $space['space_id'] : null,
            ];
            $lookup = $this->db->prepare(
                'SELECT id FROM financial_space_settings
                 WHERE scope = :scope
                   AND owner_user_id <=> :owner_id
                   AND household_id <=> :household_id
                 LIMIT 1'
            );
            $lookup->execute($params);
            if ($lookup->fetchColumn()) {
                $this->count($counts, 'financial_space_settings', 'already_present');
                continue;
            }
            $insert = $this->db->prepare(
                'INSERT INTO financial_space_settings (
                    scope, owner_user_id, household_id, month_start_day, budget_alert_percent,
                    reminders_enabled, reminder_days_before, timezone, created_by, updated_by,
                    created_at, updated_at
                 ) VALUES (
                    :scope, :owner_id, :household_id, :month_start_day, :budget_alert_percent,
                    :reminders_enabled, :reminder_days_before, :timezone, :created_by, :updated_by,
                    :created_at, :updated_at
                 )'
            );
            $insert->execute($params + [
                'month_start_day' => $row['month_start_day'],
                'budget_alert_percent' => $row['budget_alert_percent'],
                'reminders_enabled' => $row['reminders_enabled'],
                'reminder_days_before' => $row['reminder_days_before'],
                'timezone' => $row['timezone'],
                'created_by' => $userId,
                'updated_by' => $userId,
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ]);
            $this->count($counts, 'financial_space_settings', 'added');
        }
    }

    private function restoreBudgets(int $userId, array $data, array $mapping, array $categories, array &$counts): void
    {
        foreach ($data['budgets'] as $row) {
            $space = $this->mappedSpace($row, $userId, $mapping);
            if ($space === null) {
                $this->count($counts, 'budgets', 'skipped');
                continue;
            }
            $sourceCategory = $this->sourceRowById($data['categories'], $row['category_id']);
            $categoryId = $categories[$sourceCategory['kind'] . '|' . $sourceCategory['code']] ?? null;
            if ($categoryId === null) {
                throw new DomainException('Una categoría de presupuesto del respaldo no existe en este sistema.');
            }
            $lookup = $this->db->prepare(
                'SELECT id, period_end, amount_limit FROM budgets
                 WHERE scope = :scope AND space_key = :space_id
                   AND category_id = :category_id AND period_start = :period_start
                 LIMIT 1'
            );
            $lookup->execute([
                'scope' => $space['scope'],
                'space_id' => $space['space_id'],
                'category_id' => $categoryId,
                'period_start' => $row['period_start'],
            ]);
            $existing = $lookup->fetch();
            if ($existing) {
                if ((string) $existing['period_end'] !== (string) $row['period_end']
                    || (string) $existing['amount_limit'] !== (string) $row['amount_limit']) {
                    throw new DomainException('Un presupuesto existente no coincide con el respaldo; no se modificó ningún dato.');
                }
                $this->count($counts, 'budgets', 'already_present');
                continue;
            }
            $insert = $this->db->prepare(
                'INSERT INTO budgets (
                    scope, owner_user_id, household_id, category_id, period_start, period_end,
                    amount_limit, created_by, updated_by, created_at, updated_at
                 ) VALUES (
                    :scope, :owner_id, :household_id, :category_id, :period_start, :period_end,
                    :amount_limit, :created_by, :updated_by, :created_at, :updated_at
                 )'
            );
            $insert->execute([
                'scope' => $space['scope'],
                'owner_id' => $space['scope'] === 'personal' ? $space['space_id'] : null,
                'household_id' => $space['scope'] === 'household' ? $space['space_id'] : null,
                'category_id' => $categoryId,
                'period_start' => $row['period_start'],
                'period_end' => $row['period_end'],
                'amount_limit' => $row['amount_limit'],
                'created_by' => $userId,
                'updated_by' => $userId,
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ]);
            $this->count($counts, 'budgets', 'added');
        }
    }

    private function restoreGoals(int $userId, string $origin, array $data, array $mapping, array &$counts): array
    {
        $goalMap = [];
        foreach ($data['savings_goals'] as $row) {
            $space = $this->mappedSpace($row, $userId, $mapping);
            if ($space === null) {
                $this->count($counts, 'savings_goals', 'skipped');
                continue;
            }
            $targetId = $this->restoredId($userId, $origin, 'savings_goal', $row, $space);
            if (!$targetId) {
                $lookup = $this->db->prepare(
                    'SELECT id FROM savings_goals
                     WHERE scope = :scope
                       AND owner_user_id <=> :owner_id
                       AND household_id <=> :household_id
                       AND name = :name AND target_amount = :target_amount
                       AND target_date <=> :target_date AND status = :status
                       AND created_at = :created_at
                     LIMIT 1'
                );
                $lookup->execute([
                    'scope' => $space['scope'],
                    'owner_id' => $space['scope'] === 'personal' ? $space['space_id'] : null,
                    'household_id' => $space['scope'] === 'household' ? $space['space_id'] : null,
                    'name' => $row['name'],
                    'target_amount' => $row['target_amount'],
                    'target_date' => $row['target_date'],
                    'status' => $row['status'],
                    'created_at' => $row['created_at'],
                ]);
                $targetId = $lookup->fetchColumn();
            }
            if (!$targetId) {
                $insert = $this->db->prepare(
                    'INSERT INTO savings_goals (
                        scope, owner_user_id, household_id, name, target_amount, target_date,
                        status, created_by, created_at, archived_at
                     ) VALUES (
                        :scope, :owner_id, :household_id, :name, :target_amount, :target_date,
                        :status, :user_id, :created_at, :archived_at
                     )'
                );
                $insert->execute([
                    'scope' => $space['scope'],
                    'owner_id' => $space['scope'] === 'personal' ? $space['space_id'] : null,
                    'household_id' => $space['scope'] === 'household' ? $space['space_id'] : null,
                    'name' => $row['name'],
                    'target_amount' => $row['target_amount'],
                    'target_date' => $row['target_date'],
                    'status' => $row['status'],
                    'user_id' => $userId,
                    'created_at' => $row['created_at'],
                    'archived_at' => $row['archived_at'],
                ]);
                $targetId = (int) $this->db->lastInsertId();
                $this->count($counts, 'savings_goals', 'added');
            } else {
                $this->count($counts, 'savings_goals', 'already_present');
            }
            $goalMap[(string) $row['id']] = (int) $targetId;
            $this->remember($userId, $origin, 'savings_goal', $row, $space, (int) $targetId);
        }

        return $goalMap;
    }

    private function restoreGoalEntries(int $userId, string $origin, array $data, array $mapping, array $goalMap, array &$counts): void
    {
        foreach ($data['savings_goal_entries'] as $row) {
            $goalId = $goalMap[(string) $row['goal_id']] ?? null;
            if ($goalId === null) {
                $this->count($counts, 'savings_goal_entries', 'skipped');
                continue;
            }
            $sourceGoal = $this->sourceRowById($data['savings_goals'], $row['goal_id']);
            $space = $this->mappedSpace($sourceGoal, $userId, $mapping);
            if ($space === null) {
                $this->count($counts, 'savings_goal_entries', 'skipped');
                continue;
            }
            $targetId = $this->restoredId($userId, $origin, 'savings_goal_entry', $row, $space);
            if (!$targetId) {
                $lookup = $this->db->prepare(
                    'SELECT id FROM savings_goal_entries
                     WHERE goal_id = :goal_id AND direction = :direction
                       AND amount = :amount AND effective_date = :effective_date
                       AND description = :description AND created_at = :created_at
                     LIMIT 1'
                );
                $lookup->execute([
                    'goal_id' => $goalId,
                    'direction' => $row['direction'],
                    'amount' => $row['amount'],
                    'effective_date' => $row['effective_date'],
                    'description' => $row['description'],
                    'created_at' => $row['created_at'],
                ]);
                $targetId = $lookup->fetchColumn();
            }
            if (!$targetId) {
                $key = $this->restoreKey('goal_entry', $origin, (string) $row['id'], $space);
                $lookup = $this->db->prepare(
                    'SELECT id FROM savings_goal_entries
                     WHERE created_by = :user_id AND idempotency_key = :key LIMIT 1'
                );
                $lookup->execute(['user_id' => $userId, 'key' => $key]);
                $targetId = $lookup->fetchColumn();
            }
            if (!$targetId) {
                $insert = $this->db->prepare(
                    'INSERT INTO savings_goal_entries (
                        goal_id, created_by, direction, amount, effective_date, description,
                        idempotency_key, created_at
                     ) VALUES (
                        :goal_id, :user_id, :direction, :amount, :effective_date, :description,
                        :key, :created_at
                     )'
                );
                $insert->execute([
                    'goal_id' => $goalId,
                    'user_id' => $userId,
                    'direction' => $row['direction'],
                    'amount' => $row['amount'],
                    'effective_date' => $row['effective_date'],
                    'description' => $row['description'],
                    'key' => $this->restoreKey('goal_entry', $origin, (string) $row['id'], $space),
                    'created_at' => $row['created_at'],
                ]);
                $targetId = (int) $this->db->lastInsertId();
                $this->count($counts, 'savings_goal_entries', 'added');
            } else {
                $targetId = (int) $targetId;
                $this->count($counts, 'savings_goal_entries', 'already_present');
            }
            $this->remember($userId, $origin, 'savings_goal_entry', $row, $space, (int) $targetId);
        }
    }

    private function restoreSchedules(
        int $userId,
        string $origin,
        array $data,
        array $mapping,
        array $accounts,
        array $categories,
        array &$counts
    ): array {
        $scheduleMap = [];
        foreach ($data['recurring_movements'] as $row) {
            $space = $this->mappedSpace($row, $userId, $mapping);
            $accountId = $accounts[(string) $row['account_id']] ?? null;
            if ($space === null || $accountId === null) {
                $this->count($counts, 'recurring_movements', 'skipped');
                continue;
            }
            $sourceCategory = $this->sourceRowById($data['categories'], $row['category_id']);
            $categoryId = $categories[$sourceCategory['kind'] . '|' . $sourceCategory['code']] ?? null;
            if ($categoryId === null) {
                throw new DomainException('Una categoría recurrente del respaldo no existe en este sistema.');
            }
            $targetId = $this->restoredId($userId, $origin, 'recurring_movement', $row, $space);
            if (!$targetId) {
                $lookup = $this->db->prepare(
                    'SELECT id FROM recurring_movements
                     WHERE scope = :scope
                       AND owner_user_id <=> :owner_id
                       AND household_id <=> :household_id
                       AND account_id = :account_id AND operation_type = :operation_type
                       AND category_id = :category_id AND description = :description
                       AND amount = :amount AND frequency = :frequency
                       AND interval_count = :interval_count AND anchor_day = :anchor_day
                       AND start_date = :start_date AND next_due_date = :next_due_date
                       AND end_date <=> :end_date AND status = :status
                       AND created_at = :created_at
                     LIMIT 1'
                );
                $lookup->execute([
                    'scope' => $space['scope'],
                    'owner_id' => $space['scope'] === 'personal' ? $space['space_id'] : null,
                    'household_id' => $space['scope'] === 'household' ? $space['space_id'] : null,
                    'account_id' => $accountId,
                    'operation_type' => $row['operation_type'],
                    'category_id' => $categoryId,
                    'description' => $row['description'],
                    'amount' => $row['amount'],
                    'frequency' => $row['frequency'],
                    'interval_count' => $row['interval_count'],
                    'anchor_day' => $row['anchor_day'],
                    'start_date' => $row['start_date'],
                    'next_due_date' => $row['next_due_date'],
                    'end_date' => $row['end_date'],
                    'status' => $row['status'],
                    'created_at' => $row['created_at'],
                ]);
                $targetId = $lookup->fetchColumn();
            }
            if (!$targetId) {
                $insert = $this->db->prepare(
                    'INSERT INTO recurring_movements (
                        scope, owner_user_id, household_id, account_id, operation_type, category_id,
                        description, amount, frequency, interval_count, anchor_day, start_date,
                        next_due_date, end_date, status, created_by, created_at, updated_at
                     ) VALUES (
                        :scope, :owner_id, :household_id, :account_id, :operation_type, :category_id,
                        :description, :amount, :frequency, :interval_count, :anchor_day, :start_date,
                        :next_due_date, :end_date, :status, :user_id, :created_at, :updated_at
                     )'
                );
                $insert->execute([
                    'scope' => $space['scope'],
                    'owner_id' => $space['scope'] === 'personal' ? $space['space_id'] : null,
                    'household_id' => $space['scope'] === 'household' ? $space['space_id'] : null,
                    'account_id' => $accountId,
                    'operation_type' => $row['operation_type'],
                    'category_id' => $categoryId,
                    'description' => $row['description'],
                    'amount' => $row['amount'],
                    'frequency' => $row['frequency'],
                    'interval_count' => $row['interval_count'],
                    'anchor_day' => $row['anchor_day'],
                    'start_date' => $row['start_date'],
                    'next_due_date' => $row['next_due_date'],
                    'end_date' => $row['end_date'],
                    'status' => $row['status'],
                    'user_id' => $userId,
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
                ]);
                $targetId = (int) $this->db->lastInsertId();
                $this->count($counts, 'recurring_movements', 'added');
            } else {
                $this->count($counts, 'recurring_movements', 'already_present');
            }
            $scheduleMap[(string) $row['id']] = (int) $targetId;
            $this->remember($userId, $origin, 'recurring_movement', $row, $space, (int) $targetId);
        }

        return $scheduleMap;
    }

    private function restoreOccurrences(
        int $userId,
        string $origin,
        array $data,
        array $mapping,
        array $scheduleMap,
        array $operationMap,
        array &$counts
    ): void {
        foreach ($data['recurring_movement_occurrences'] as $row) {
            $scheduleId = $scheduleMap[(string) $row['schedule_id']] ?? null;
            if ($scheduleId === null) {
                $this->count($counts, 'recurring_movement_occurrences', 'skipped');
                continue;
            }
            $sourceSchedule = $this->sourceRowById($data['recurring_movements'], $row['schedule_id']);
            $space = $this->mappedSpace($sourceSchedule, $userId, $mapping);
            if ($space === null) {
                $this->count($counts, 'recurring_movement_occurrences', 'skipped');
                continue;
            }
            $operationId = $row['operation_id'] === null
                ? null
                : ($operationMap[(string) $row['operation_id']] ?? null);
            if ($row['operation_id'] !== null && $operationId === null) {
                $this->count($counts, 'recurring_movement_occurrences', 'skipped');
                continue;
            }
            $targetId = $this->restoredId($userId, $origin, 'recurring_occurrence', $row, $space);
            if (!$targetId) {
                $lookup = $this->db->prepare(
                    'SELECT id, status, operation_id FROM recurring_movement_occurrences
                     WHERE schedule_id = :schedule_id AND due_date = :due_date LIMIT 1'
                );
                $lookup->execute(['schedule_id' => $scheduleId, 'due_date' => $row['due_date']]);
                $existing = $lookup->fetch();
                if ($existing) {
                    if ($existing['status'] !== $row['status']
                        || ($existing['operation_id'] === null ? null : (int) $existing['operation_id']) !== $operationId) {
                        throw new DomainException('Una recurrencia existente no coincide con el respaldo; no se modificó ningún dato.');
                    }
                    $targetId = (int) $existing['id'];
                }
            }
            if (!$targetId) {
                $insert = $this->db->prepare(
                    'INSERT INTO recurring_movement_occurrences (
                        schedule_id, due_date, status, operation_id, created_by,
                        confirmed_by, created_at, confirmed_at
                     ) VALUES (
                        :schedule_id, :due_date, :status, :operation_id, :user_id,
                        :confirmed_by, :created_at, :confirmed_at
                     )'
                );
                $insert->execute([
                    'schedule_id' => $scheduleId,
                    'due_date' => $row['due_date'],
                    'status' => $row['status'],
                    'operation_id' => $operationId,
                    'user_id' => $userId,
                    'confirmed_by' => $row['confirmed_by'] === null ? null : $userId,
                    'created_at' => $row['created_at'],
                    'confirmed_at' => $row['confirmed_at'],
                ]);
                $targetId = (int) $this->db->lastInsertId();
                $this->count($counts, 'recurring_movement_occurrences', 'added');
            } else {
                $targetId = (int) $targetId;
                $this->count($counts, 'recurring_movement_occurrences', 'already_present');
            }
            $this->remember($userId, $origin, 'recurring_occurrence', $row, $space, (int) $targetId);
        }
    }
}
