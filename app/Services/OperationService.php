<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DomainException;
use App\Repositories\AccountRepository;
use App\Repositories\LiabilityRepository;
use App\Repositories\OperationRepository;
use App\Support\DateValue;
use App\Support\DecimalAmount;
use PDO;
use PDOException;
use Throwable;

final class OperationService
{
    public function __construct(
        private readonly PDO $db,
        private readonly AccountRepository $accounts,
        private readonly OperationRepository $operations,
        private readonly LiabilityRepository $liabilities,
    ) {
    }

    public function categories(?int $userId = null): array
    {
        return $this->operations->categories($userId);
    }

    public function recentForUser(int $userId): array
    {
        return $this->operations->recentForUser($userId);
    }

    public function createSingle(
        int $userId,
        array $input,
        ?int $idempotencyOwnerUserId = null
    ): int {
        $idempotencyOwnerUserId ??= $userId;
        $typeValue = $input['operation_type'] ?? '';
        if (!is_string($typeValue)) {
            throw new DomainException('Selecciona un tipo de movimiento válido.');
        }
        $type = $typeValue;
        if (!in_array($type, ['income', 'expense', 'adjustment'], true)) {
            throw new DomainException('Selecciona un tipo de movimiento válido.');
        }

        $accountId = $this->positiveId($input['account_id'] ?? null, 'Selecciona una cuenta válida.');
        $amount = DecimalAmount::normalize($input['amount'] ?? null);
        $description = $this->description($input['description'] ?? null);
        $date = DateValue::normalize($input['effective_date'] ?? null, date('Y-m-d'));
        if ($date > date('Y-m-d')) {
            throw new DomainException('Un movimiento confirmado no puede tener fecha futura.');
        }
        $key = $this->idempotencyKey($input['idempotency_key'] ?? null);

        $directionValue = $input['direction'] ?? '';
        if (!is_string($directionValue)) {
            throw new DomainException('Selecciona si el ajuste aumenta o disminuye el saldo.');
        }
        $direction = match ($type) {
            'income' => 'inflow',
            'expense' => 'outflow',
            default => $directionValue,
        };
        if (!in_array($direction, ['inflow', 'outflow'], true)) {
            throw new DomainException('Selecciona si el ajuste aumenta o disminuye el saldo.');
        }

        $categoryId = null;
        if ($type === 'income' || $type === 'expense') {
            $categoryId = $this->positiveId($input['category_id'] ?? null, 'Selecciona una categoría.');
            $category = $this->operations->findCategory($categoryId);
            if (!$category || $category['kind'] !== $type) {
                throw new DomainException('La categoría no corresponde al tipo de movimiento seleccionado.');
            }
        } elseif (!empty($input['category_id'])) {
            throw new DomainException('Los ajustes de saldo no llevan categoría.');
        }

        try {
            $this->beginFinancialTransaction();
            $existingId = $this->operations->findByIdempotencyKey($idempotencyOwnerUserId, $key);
            if ($existingId !== null) {
                $this->db->commit();
                return $existingId;
            }

            $account = $this->lockAuthorizedAccount($accountId, $userId);
            if (
                $categoryId !== null
                && !OperationRepository::categoryFitsSpace(
                    $category,
                    (string) $account['scope'],
                    $account['owner_user_id'] === null ? null : (int) $account['owner_user_id'],
                    $account['household_id'] === null ? null : (int) $account['household_id']
                )
            ) {
                throw new DomainException('La categoría no pertenece al espacio de la cuenta seleccionada.');
            }
            if ($date < $account['initial_balance_date']) {
                throw new DomainException('La fecha del movimiento no puede ser anterior al saldo inicial de la cuenta.');
            }
            if ($direction === 'outflow') {
                $this->ensureWithdrawalIsAllowed($account, $amount);
            } else {
                $this->ensureDepositIsWithinMaximum($account, $amount);
            }

            $operationId = $this->operations->create([
                'operation_type' => $type,
                'scope' => $account['scope'],
                'owner_user_id' => $account['owner_user_id'],
                'household_id' => $account['household_id'],
                'created_by' => $idempotencyOwnerUserId,
                'category_id' => $categoryId,
                'description' => $description,
                'effective_date' => $date,
                'idempotency_key' => $key,
                'reversal_of_operation_id' => null,
            ]);
            $this->operations->addMovement($operationId, $accountId, $direction, $amount);
            $this->audit(
                $userId,
                $account['household_id'] === null ? null : (int) $account['household_id'],
                'finance.operation_confirmed',
                'operation',
                $operationId
            );
            $this->db->commit();

            return $operationId;
        } catch (PDOException $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($exception->getCode() === '23000') {
                $existingId = $this->operations->findByIdempotencyKey($idempotencyOwnerUserId, $key);
                if ($existingId !== null) {
                    return $existingId;
                }
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function createCsvRowsWithinTransaction(int $userId, int $accountId, int $batchId, array $rows): array
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('CSV rows must be created inside the import transaction.');
        }

        $account = $this->lockAuthorizedAccount($accountId, $userId);
        $balance = $this->accounts->balanceForAccount($accountId);
        $categoriesById = [];
        foreach ($this->operations->categories($userId) as $category) {
            if (!OperationRepository::categoryFitsSpace(
                $category,
                (string) $account['scope'],
                $account['owner_user_id'] === null ? null : (int) $account['owner_user_id'],
                $account['household_id'] === null ? null : (int) $account['household_id']
            )) {
                continue;
            }
            $categoriesById[(int) $category['id']] = $category;
        }
        $operationIds = [];
        foreach ($rows as $row) {
            if ((int) $row['is_duplicate'] === 1) {
                continue;
            }
            $type = $row['operation_type'];
            if (!in_array($type, ['income', 'expense'], true)) {
                throw new DomainException('El archivo contiene un tipo de movimiento no válido.');
            }
            $categoryId = (int) $row['category_id'];
            $category = $categoriesById[$categoryId] ?? null;
            if (!$category || $category['kind'] !== $type) {
                throw new DomainException('Una categoría del archivo ya no está disponible para ese movimiento.');
            }
            $amount = DecimalAmount::normalize((string) $row['amount']);
            $date = DateValue::normalize((string) $row['effective_date'], date('Y-m-d'));
            if ($date > date('Y-m-d')) {
                throw new DomainException('El archivo contiene movimientos con fecha futura.');
            }
            if ($date < $account['initial_balance_date']) {
                throw new DomainException('La fecha de un movimiento es anterior al saldo inicial de la cuenta.');
            }
            $fingerprint = (string) $row['import_fingerprint'];
            if (!preg_match('/\A[a-f0-9]{64}\z/', $fingerprint)) {
                throw new DomainException('El archivo contiene una huella de importación inválida.');
            }
            $lineNumber = (int) $row['line_number'];
            $idempotencyKey = hash('sha256', 'csv-import:' . $batchId . ':' . $lineNumber);
            $existingId = $this->operations->findByImportFingerprint($userId, $fingerprint);
            if ($existingId !== null) {
                $operationIds[$lineNumber] = $existingId;
                continue;
            }

            $balance = $this->balanceAfterCsvMovement($account, $balance, $type, $amount);
            $description = (string) $row['description'];
            $operationId = $this->operations->create([
                'operation_type' => $type,
                'scope' => $account['scope'],
                'owner_user_id' => $account['owner_user_id'],
                'household_id' => $account['household_id'],
                'created_by' => $userId,
                'category_id' => $categoryId,
                'description' => $description,
                'effective_date' => $date,
                'idempotency_key' => $idempotencyKey,
                'import_fingerprint' => $fingerprint,
                'reversal_of_operation_id' => null,
            ]);
            $direction = $type === 'income' ? 'inflow' : 'outflow';
            $this->operations->addMovement($operationId, $accountId, $direction, $amount);
            $this->audit(
                $userId,
                $account['household_id'] === null ? null : (int) $account['household_id'],
                'finance.operation_imported',
                'operation',
                $operationId
            );
            $operationIds[$lineNumber] = $operationId;
        }

        return $operationIds;
    }

    private function balanceAfterCsvMovement(array $account, string $balance, string $type, string $amount): string
    {
        $direction = $type === 'expense' ? 'outflow' : 'inflow';
        $limit = $account['overdraft_enabled'] ? (string) $account['overdraft_limit'] : '0.00';
        $statement = $this->db->prepare(
            'SELECT
                CAST(
                    CAST(:balance_after AS DECIMAL(15,2))
                    + CASE WHEN :direction_after = "outflow"
                           THEN CAST(:amount_after AS DECIMAL(15,2)) * -1
                           ELSE CAST(:amount_after AS DECIMAL(15,2))
                      END
                    AS CHAR
                ) AS balance_after,
                CASE
                    WHEN :direction_check = "outflow"
                        THEN CAST(:balance_check AS DECIMAL(15,2)) - CAST(:amount_check AS DECIMAL(15,2))
                             >= CAST(:overdraft_limit AS DECIMAL(15,2)) * -1
                    ELSE CAST(:balance_deposit AS DECIMAL(15,2)) + CAST(:amount_deposit AS DECIMAL(15,2))
                         <= 9999999999999.99
                END AS allowed'
        );
        $statement->execute([
            'balance_after' => $balance,
            'direction_after' => $direction,
            'amount_after' => $amount,
            'direction_check' => $direction,
            'balance_check' => $balance,
            'amount_check' => $amount,
            'overdraft_limit' => $limit,
            'balance_deposit' => $balance,
            'amount_deposit' => $amount,
        ]);
        $result = $statement->fetch();
        if (!(bool) $result['allowed']) {
            throw new DomainException(
                $direction === 'outflow'
                    ? 'Un movimiento importado supera el saldo disponible y el sobregiro autorizado.'
                    : 'Un movimiento importado supera el saldo máximo permitido para la cuenta.'
            );
        }

        return (string) $result['balance_after'];
    }

    public function transfer(int $userId, array $input): int
    {
        $sourceId = $this->positiveId($input['source_account_id'] ?? null, 'Selecciona una cuenta de origen.');
        $destinationId = $this->positiveId($input['destination_account_id'] ?? null, 'Selecciona una cuenta de destino.');
        if ($sourceId === $destinationId) {
            throw new DomainException('La cuenta de origen y destino deben ser diferentes.');
        }

        $amount = DecimalAmount::normalize($input['amount'] ?? null);
        $description = $this->description($input['description'] ?? null);
        $date = DateValue::normalize($input['effective_date'] ?? null, date('Y-m-d'));
        if ($date > date('Y-m-d')) {
            throw new DomainException('Una transferencia confirmada no puede tener fecha futura.');
        }
        $key = $this->idempotencyKey($input['idempotency_key'] ?? null);

        try {
            $this->beginFinancialTransaction();
            $existingId = $this->operations->findByIdempotencyKey($userId, $key);
            if ($existingId !== null) {
                $this->db->commit();
                return $existingId;
            }

            $accounts = [];
            $ids = [$sourceId, $destinationId];
            sort($ids, SORT_NUMERIC);
            foreach ($ids as $id) {
                $account = $this->accounts->findForUpdate($id);
                if (!$account || $account['status'] !== 'active') {
                    throw new DomainException('Una de las cuentas no existe o está archivada.');
                }
                $accounts[$id] = $account;
            }

            $source = $accounts[$sourceId];
            $destination = $accounts[$destinationId];
            $this->assertAccountAccess($source, $userId);
            $this->assertAccountAccess($destination, $userId);
            $this->assertSameFinancialSpace($source, $destination);

            if (
                $date < $source['initial_balance_date']
                || $date < $destination['initial_balance_date']
            ) {
                throw new DomainException('La fecha de la transferencia no puede ser anterior al saldo inicial de una cuenta.');
            }

            $destinationAmount = $amount;
            if ($source['currency'] !== $destination['currency']) {
                if (!isset($input['destination_amount']) || trim((string) $input['destination_amount']) === '') {
                    throw new DomainException('Indica el valor recibido en la cuenta de destino (' . $destination['currency'] . ').');
                }
                $destinationAmount = DecimalAmount::normalize($input['destination_amount']);
            }

            $this->ensureWithdrawalIsAllowed($source, $amount);
            $this->ensureDepositIsWithinMaximum($destination, $destinationAmount);
            $operationId = $this->operations->create([
                'operation_type' => 'transfer',
                'scope' => $source['scope'],
                'owner_user_id' => $source['owner_user_id'],
                'household_id' => $source['household_id'],
                'created_by' => $userId,
                'category_id' => null,
                'description' => $description,
                'effective_date' => $date,
                'idempotency_key' => $key,
                'reversal_of_operation_id' => null,
            ]);
            $sourceMovementId = $this->operations->addMovement(
                $operationId,
                $sourceId,
                'outflow',
                $amount
            );
            $destinationMovementId = $this->operations->addMovement(
                $operationId,
                $destinationId,
                'inflow',
                $destinationAmount
            );
            $this->operations->addTransfer($operationId, $sourceMovementId, $destinationMovementId);
            $this->audit(
                $userId,
                $source['household_id'] === null ? null : (int) $source['household_id'],
                'finance.transfer_confirmed',
                'operation',
                $operationId
            );
            $this->db->commit();

            return $operationId;
        } catch (PDOException $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($exception->getCode() === '23000') {
                $existingId = $this->operations->findByIdempotencyKey($userId, $key);
                if ($existingId !== null) {
                    return $existingId;
                }
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function reverse(int $userId, int $operationId, mixed $key): int
    {
        $key = $this->idempotencyKey($key);

        try {
            $this->beginFinancialTransaction();
            $existingId = $this->operations->findByIdempotencyKey($userId, $key);
            if ($existingId !== null) {
                $this->db->commit();
                return $existingId;
            }

            $original = $this->operations->findForUpdate($operationId);
            if (
                !$original
                || $original['status'] !== 'confirmed'
                || $original['operation_type'] === 'reversal'
                || $original['confirmed_at'] === null
            ) {
                throw new DomainException('La operación no existe o ya fue anulada.');
            }

            $movements = $this->operations->movementsForOperation($operationId);
            $liabilityMovements = $this->operations->liabilityMovementsForOperation($operationId);
            if (!$movements && !$liabilityMovements) {
                throw new DomainException('La operación no tiene movimientos confirmados para anular.');
            }

            $accountIds = array_values(array_unique(array_map(
                static fn (array $movement): int => (int) $movement['account_id'],
                $movements
            )));
            sort($accountIds, SORT_NUMERIC);
            $lockedAccounts = [];
            foreach ($accountIds as $accountId) {
                $account = $this->accounts->findForUpdate($accountId);
                if (!$account || $account['status'] !== 'active') {
                    throw new DomainException('Activa las cuentas asociadas antes de anular esta operación.');
                }
                $this->assertAccountAccess($account, $userId);
                $lockedAccounts[$accountId] = $account;
            }

            $liabilityIds = array_values(array_unique(array_map(
                static fn (array $movement): int => (int) $movement['liability_id'],
                $liabilityMovements
            )));
            sort($liabilityIds, SORT_NUMERIC);
            $lockedLiabilities = [];
            foreach ($liabilityIds as $liabilityId) {
                $liability = $this->liabilities->findForUpdate($liabilityId);
                if (!$liability || $liability['status'] !== 'active') {
                    throw new DomainException('Activa las deudas asociadas antes de anular esta operación.');
                }
                $this->assertLiabilityAccess($liability, $userId);
                $lockedLiabilities[$liabilityId] = $liability;
            }

            foreach ($movements as $movement) {
                $account = $lockedAccounts[(int) $movement['account_id']];
                $reversalDirection = $movement['direction'] === 'inflow' ? 'outflow' : 'inflow';
                if ($reversalDirection === 'outflow') {
                    $this->ensureWithdrawalIsAllowed($account, (string) $movement['amount']);
                } else {
                    $this->ensureDepositIsWithinMaximum($account, (string) $movement['amount']);
                }
            }

            foreach ($liabilityMovements as $movement) {
                $liability = $lockedLiabilities[(int) $movement['liability_id']];
                $reversalDirection = $movement['direction'] === 'increase' ? 'decrease' : 'increase';
                if ($reversalDirection === 'decrease') {
                    $this->ensureLiabilityCanDecrease($liability, (string) $movement['amount']);
                } else {
                    $this->ensureLiabilityCanIncrease($liability, (string) $movement['amount']);
                }
            }

            $reversalId = $this->operations->create([
                'operation_type' => 'reversal',
                'scope' => $original['scope'],
                'owner_user_id' => $original['owner_user_id'],
                'household_id' => $original['household_id'],
                'created_by' => $userId,
                'category_id' => $original['category_id'],
                'description' => 'Anulación de operación #' . $operationId,
                'effective_date' => date('Y-m-d'),
                'idempotency_key' => $key,
                'reversal_of_operation_id' => $operationId,
            ]);

            foreach ($movements as $movement) {
                $direction = $movement['direction'] === 'inflow' ? 'outflow' : 'inflow';
                $this->operations->addMovement(
                    $reversalId,
                    (int) $movement['account_id'],
                    $direction,
                    (string) $movement['amount']
                );
            }

            foreach ($liabilityMovements as $movement) {
                $direction = $movement['direction'] === 'increase' ? 'decrease' : 'increase';
                $this->operations->addLiabilityMovement(
                    $reversalId,
                    (int) $movement['liability_id'],
                    $direction,
                    (string) $movement['amount'],
                    (string) $movement['interest_amount']
                );
            }

            $this->operations->cancel($operationId);
            $householdId = $original['household_id'] === null ? null : (int) $original['household_id'];
            $this->audit($userId, $householdId, 'finance.operation_reversed', 'operation', $operationId);
            $this->db->commit();

            return $reversalId;
        } catch (PDOException $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($exception->getCode() === '23000') {
                $existingId = $this->operations->findByIdempotencyKey($userId, $key);
                if ($existingId !== null) {
                    return $existingId;
                }
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function lockAuthorizedAccount(int $accountId, int $userId): array
    {
        $account = $this->accounts->findForUpdate($accountId);
        if (!$account || $account['status'] !== 'active') {
            throw new DomainException('La cuenta no existe o está archivada.');
        }

        $this->assertAccountAccess($account, $userId);

        return $account;
    }

    private function beginFinancialTransaction(): void
    {
        // Account locks serialize writes; this makes the post-lock balance read see the latest commit.
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->db->beginTransaction();
    }

    private function assertAccountAccess(array $account, int $userId): void
    {
        if ($account['scope'] === 'personal') {
            if ((int) $account['owner_user_id'] !== $userId) {
                throw new DomainException('No tienes permiso para usar esta cuenta personal.');
            }
            return;
        }

        if (!$this->accounts->memberRole((int) $account['household_id'], $userId, true)) {
            throw new DomainException('No perteneces al hogar de esta cuenta.');
        }
    }

    private function assertSameFinancialSpace(array $source, array $destination): void
    {
        if ($source['scope'] !== $destination['scope']) {
            throw new DomainException('Las transferencias deben realizarse dentro del mismo espacio financiero.');
        }

        $sameOwner = $source['scope'] === 'personal'
            && (int) $source['owner_user_id'] === (int) $destination['owner_user_id'];
        $sameHousehold = $source['scope'] === 'household'
            && (int) $source['household_id'] === (int) $destination['household_id'];

        if (!$sameOwner && !$sameHousehold) {
            throw new DomainException('Las cuentas no pertenecen al mismo espacio financiero.');
        }
    }

    private function ensureWithdrawalIsAllowed(array $account, string $amount): void
    {
        $balance = $this->accounts->balanceForAccount((int) $account['id']);
        $limit = $account['overdraft_enabled'] ? (string) $account['overdraft_limit'] : '0.00';
        $statement = $this->db->prepare(
            'SELECT (
                CAST(:balance AS DECIMAL(15,2)) - CAST(:amount AS DECIMAL(15,2))
                >= CAST(:limit AS DECIMAL(15,2)) * -1
             )'
        );
        $statement->execute([
            'balance' => $balance,
            'amount' => $amount,
            'limit' => $limit,
        ]);

        if (!(bool) $statement->fetchColumn()) {
            throw new DomainException('El movimiento supera el saldo disponible y el sobregiro autorizado.');
        }
    }

    private function ensureDepositIsWithinMaximum(array $account, string $amount): void
    {
        $balance = $this->accounts->balanceForAccount((int) $account['id']);
        $statement = $this->db->prepare(
            'SELECT (
                CAST(:balance AS DECIMAL(15,2)) + CAST(:amount AS DECIMAL(15,2))
                <= 9999999999999.99
             )'
        );
        $statement->execute([
            'balance' => $balance,
            'amount' => $amount,
        ]);

        if (!(bool) $statement->fetchColumn()) {
            throw new DomainException('El movimiento supera el saldo máximo permitido para la cuenta.');
        }
    }

    private function assertLiabilityAccess(array $liability, int $userId): void
    {
        if ($liability['scope'] === 'personal') {
            if ((int) $liability['owner_user_id'] !== $userId) {
                throw new DomainException('No tienes permiso para usar esta deuda personal.');
            }
            return;
        }
        if (!$this->accounts->memberRole((int) $liability['household_id'], $userId, true)) {
            throw new DomainException('No perteneces al hogar de esta deuda.');
        }
    }

    private function ensureLiabilityCanDecrease(array $liability, string $amount): void
    {
        if ($amount === '0.00') {
            return;
        }
        $balance = $this->liabilities->balanceForLiability((int) $liability['id']);
        $statement = $this->db->prepare(
            'SELECT CAST(:balance AS DECIMAL(15,2)) >= CAST(:amount AS DECIMAL(15,2))'
        );
        $statement->execute(['balance' => $balance, 'amount' => $amount]);
        if (!(bool) $statement->fetchColumn()) {
            throw new DomainException('La deuda no conserva saldo suficiente para revertir el cargo.');
        }
    }

    private function ensureLiabilityCanIncrease(array $liability, string $amount): void
    {
        $balance = $this->liabilities->balanceForLiability((int) $liability['id']);
        $limitCheck = $liability['kind'] === 'credit_card'
            ? 'CAST(:balance AS DECIMAL(15,2)) + CAST(:amount AS DECIMAL(15,2)) <= CAST(:limit AS DECIMAL(15,2))'
            : 'CAST(:balance AS DECIMAL(15,2)) + CAST(:amount AS DECIMAL(15,2)) <= 9999999999999.99';
        $statement = $this->db->prepare('SELECT (' . $limitCheck . ')');
        $params = ['balance' => $balance, 'amount' => $amount];
        if ($liability['kind'] === 'credit_card') {
            $params['limit'] = $liability['credit_limit'];
        }
        $statement->execute($params);
        if (!(bool) $statement->fetchColumn()) {
            throw new DomainException('La anulación supera el cupo o el saldo máximo permitido para la deuda.');
        }
    }

    private function idempotencyKey(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\A[a-f0-9]{64}\z/', $value)) {
            throw new DomainException('Actualiza el formulario e inténtalo nuevamente.');
        }

        return $value;
    }

    private function positiveId(mixed $value, string $message): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            throw new DomainException($message);
        }

        return (int) $id;
    }

    private function description(mixed $value): string
    {
        if (!is_string($value)) {
            throw new DomainException('La descripción no es válida.');
        }

        $description = trim($value);
        if ($description === '' || mb_strlen($description) > 255) {
            throw new DomainException('La descripción debe tener entre 1 y 255 caracteres.');
        }

        return $description;
    }

    private function audit(
        int $userId,
        ?int $householdId,
        string $event,
        string $targetType,
        int $targetId
    ): void {
        $statement = $this->db->prepare(
            'INSERT INTO audit_log (
                actor_user_id, household_id, event_type, target_type, target_id
             ) VALUES (
                :actor_user_id, :household_id, :event_type, :target_type, :target_id
             )'
        );
        $statement->bindValue(':actor_user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(
            ':household_id',
            $householdId,
            $householdId === null ? PDO::PARAM_NULL : PDO::PARAM_INT
        );
        $statement->bindValue(':event_type', $event, PDO::PARAM_STR);
        $statement->bindValue(':target_type', $targetType, PDO::PARAM_STR);
        $statement->bindValue(':target_id', $targetId, PDO::PARAM_INT);
        $statement->execute();
    }
}
