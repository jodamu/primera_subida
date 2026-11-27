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

final class LiabilityService
{
    public function __construct(
        private readonly PDO $db,
        private readonly AccountRepository $accounts,
        private readonly LiabilityRepository $liabilities,
        private readonly OperationRepository $operations,
    ) {
    }

    public function listForUser(int $userId, bool $activeOnly = false): array
    {
        return $this->liabilities->listForUser($userId, $activeOnly);
    }

    public function create(int $userId, array $input): int
    {
        $kind = $this->stringValue($input['kind'] ?? null, 'Selecciona un tipo de deuda válido.');
        if (!in_array($kind, ['credit_card', 'loan', 'other'], true)) {
            throw new DomainException('Selecciona un tipo de deuda válido.');
        }
        $name = trim($this->stringValue($input['name'] ?? null, 'Ingresa un nombre válido.'));
        if ($name === '' || mb_strlen($name) > 100) {
            throw new DomainException('El nombre debe tener entre 1 y 100 caracteres.');
        }
        $creditor = trim($this->stringValue($input['creditor'] ?? '', 'El acreedor no es válido.'));
        if (mb_strlen($creditor) > 120) {
            throw new DomainException('El nombre del acreedor no puede superar 120 caracteres.');
        }
        $scope = $this->stringValue($input['scope'] ?? null, 'Selecciona un ámbito válido.');
        if (!in_array($scope, ['personal', 'household'], true)) {
            throw new DomainException('Selecciona un ámbito válido.');
        }

        $initialBalance = DecimalAmount::normalize($input['initial_balance'] ?? '0', true);
        $initialDate = DateValue::normalize($input['initial_balance_date'] ?? null, date('Y-m-d'));
        if ($initialDate > date('Y-m-d')) {
            throw new DomainException('La fecha inicial de la deuda no puede estar en el futuro.');
        }

        $creditLimit = null;
        $statementDay = null;
        $dueDay = null;
        if ($kind === 'credit_card') {
            $creditLimit = DecimalAmount::normalize($input['credit_limit'] ?? null);
            if (!$this->amountLessThanOrEqual($initialBalance, $creditLimit)) {
                throw new DomainException('La deuda inicial no puede superar el cupo de la tarjeta.');
            }
            $statementDay = $this->dayOfMonth($input['statement_close_day'] ?? null);
            $dueDay = $this->dayOfMonth($input['payment_due_day'] ?? null);
        }

        $interestRate = $this->interestRate($input['annual_interest_rate'] ?? '');
        $householdId = null;
        $ownerUserId = $userId;
        if ($scope === 'household') {
            $householdId = $this->positiveId($input['household_id'] ?? null, 'Selecciona un hogar válido.');
            $ownerUserId = null;
        }

        $this->db->beginTransaction();
        try {
            if ($householdId !== null) {
                $role = $this->accounts->memberRole($householdId, $userId, true);
                if (!$role || !in_array($role, ['owner', 'admin'], true)) {
                    throw new DomainException('Solo el propietario o un administrador puede crear deudas familiares.');
                }
            }

            $liabilityId = $this->liabilities->create([
                'kind' => $kind,
                'name' => $name,
                'creditor' => $creditor === '' ? null : $creditor,
                'scope' => $scope,
                'owner_user_id' => $ownerUserId,
                'household_id' => $householdId,
                'created_by' => $userId,
                'initial_balance' => $initialBalance,
                'initial_balance_date' => $initialDate,
                'credit_limit' => $creditLimit,
                'statement_close_day' => $statementDay,
                'payment_due_day' => $dueDay,
                'annual_interest_rate' => $interestRate,
            ]);
            $this->audit($userId, $householdId, 'finance.liability_created', 'liability', $liabilityId);
            $this->db->commit();

            return $liabilityId;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function charge(int $userId, array $input): int
    {
        $liabilityId = $this->positiveId($input['liability_id'] ?? null, 'Selecciona una deuda válida.');
        $categoryId = $this->positiveId($input['category_id'] ?? null, 'Selecciona una categoría.');
        $amount = DecimalAmount::normalize($input['amount'] ?? null);
        $description = $this->description($input['description'] ?? null);
        $date = $this->operationDate($input['effective_date'] ?? null);
        $key = $this->idempotencyKey($input['idempotency_key'] ?? null);
        $category = $this->operations->findCategory($categoryId);
        if (!$category || $category['kind'] !== 'expense') {
            throw new DomainException('Selecciona una categoría de gasto válida.');
        }

        try {
            $this->beginFinancialTransaction();
            $existingId = $this->operations->findByIdempotencyKey($userId, $key);
            if ($existingId !== null) {
                $this->db->commit();
                return $existingId;
            }

            $liability = $this->liabilities->findForUpdate($liabilityId);
            $this->assertLiabilityAccess($liability, $userId);
            if (!\App\Repositories\OperationRepository::categoryFitsSpace(
                $category,
                (string) $liability['scope'],
                $liability['owner_user_id'] === null ? null : (int) $liability['owner_user_id'],
                $liability['household_id'] === null ? null : (int) $liability['household_id']
            )) {
                throw new DomainException('La categoría no pertenece al espacio de la deuda seleccionada.');
            }
            if ($date < $liability['initial_balance_date']) {
                throw new DomainException('La fecha del cargo no puede ser anterior a la fecha inicial de la deuda.');
            }
            $this->ensureDebtCanIncrease($liability, $amount);
            $operationId = $this->operations->create([
                'operation_type' => 'expense',
                'scope' => $liability['scope'],
                'owner_user_id' => $liability['owner_user_id'],
                'household_id' => $liability['household_id'],
                'created_by' => $userId,
                'category_id' => $categoryId,
                'description' => $description,
                'effective_date' => $date,
                'idempotency_key' => $key,
                'reversal_of_operation_id' => null,
            ]);
            $this->operations->addLiabilityMovement($operationId, $liabilityId, 'increase', $amount);
            $this->audit(
                $userId,
                $liability['household_id'] === null ? null : (int) $liability['household_id'],
                'finance.liability_charge',
                'liability',
                $liabilityId
            );
            $this->db->commit();

            return $operationId;
        } catch (PDOException $exception) {
            $this->rollback();
            if ($exception->getCode() === '23000') {
                $existingId = $this->operations->findByIdempotencyKey($userId, $key);
                if ($existingId !== null) {
                    return $existingId;
                }
            }
            throw $exception;
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    public function payment(int $userId, array $input): int
    {
        $liabilityId = $this->positiveId($input['liability_id'] ?? null, 'Selecciona una deuda válida.');
        $accountId = $this->positiveId($input['account_id'] ?? null, 'Selecciona una cuenta de pago.');
        $principal = DecimalAmount::normalize($input['principal_amount'] ?? null, true);
        $interest = DecimalAmount::normalize($input['interest_amount'] ?? '0', true);
        if ($principal === '0.00' && $interest === '0.00') {
            throw new DomainException('El pago debe tener un valor mayor que cero.');
        }
        $description = $this->description($input['description'] ?? null);
        $date = $this->operationDate($input['effective_date'] ?? null);
        $key = $this->idempotencyKey($input['idempotency_key'] ?? null);

        try {
            $this->beginFinancialTransaction();
            $existingId = $this->operations->findByIdempotencyKey($userId, $key);
            if ($existingId !== null) {
                $this->db->commit();
                return $existingId;
            }

            $account = $this->accounts->findForUpdate($accountId);
            $this->assertAccountAccess($account, $userId);
            $liability = $this->liabilities->findForUpdate($liabilityId);
            $this->assertLiabilityAccess($liability, $userId);
            $this->assertSameFinancialSpace($account, $liability);
            if ($account['currency'] !== $liability['currency']) {
                throw new DomainException('El pago requiere una cuenta en la misma moneda que la deuda.');
            }
            if ($date < $account['initial_balance_date'] || $date < $liability['initial_balance_date']) {
                throw new DomainException('La fecha del pago no puede ser anterior a la fecha inicial de una cuenta.');
            }
            if ($liability['kind'] === 'credit_card' && $interest !== '0.00') {
                throw new DomainException('Registra los intereses de la tarjeta como un cargo separado antes de pagarla.');
            }
            $this->ensureLiabilityCanDecrease($liability, $principal);
            $paymentAmount = $this->addAmounts($principal, $interest);
            $this->ensureWithdrawalIsAllowed($account, $paymentAmount);

            $interestCategoryId = $interest === '0.00'
                ? null
                : $this->operations->findExpenseCategoryByCode('loan_interest');
            if ($interest !== '0.00' && $interestCategoryId === null) {
                throw new \RuntimeException('Falta la categoría de intereses en la base de datos.');
            }
            $operationId = $this->operations->create([
                'operation_type' => 'liability_payment',
                'scope' => $liability['scope'],
                'owner_user_id' => $liability['owner_user_id'],
                'household_id' => $liability['household_id'],
                'created_by' => $userId,
                'category_id' => $interestCategoryId,
                'description' => $description,
                'effective_date' => $date,
                'idempotency_key' => $key,
                'reversal_of_operation_id' => null,
            ]);
            $this->operations->addMovement($operationId, $accountId, 'outflow', $paymentAmount);
            $this->operations->addLiabilityMovement(
                $operationId,
                $liabilityId,
                'decrease',
                $principal,
                $interest
            );
            $this->audit(
                $userId,
                $liability['household_id'] === null ? null : (int) $liability['household_id'],
                'finance.liability_payment',
                'liability',
                $liabilityId
            );
            $this->db->commit();

            return $operationId;
        } catch (PDOException $exception) {
            $this->rollback();
            if ($exception->getCode() === '23000') {
                $existingId = $this->operations->findByIdempotencyKey($userId, $key);
                if ($existingId !== null) {
                    return $existingId;
                }
            }
            throw $exception;
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    public function disburse(int $userId, array $input): int
    {
        $liabilityId = $this->positiveId($input['liability_id'] ?? null, 'Selecciona una deuda válida.');
        $accountId = $this->positiveId($input['account_id'] ?? null, 'Selecciona una cuenta de destino.');
        $amount = DecimalAmount::normalize($input['amount'] ?? null);
        $description = $this->description($input['description'] ?? null);
        $date = $this->operationDate($input['effective_date'] ?? null);
        $key = $this->idempotencyKey($input['idempotency_key'] ?? null);

        try {
            $this->beginFinancialTransaction();
            $existingId = $this->operations->findByIdempotencyKey($userId, $key);
            if ($existingId !== null) {
                $this->db->commit();
                return $existingId;
            }

            $account = $this->accounts->findForUpdate($accountId);
            $this->assertAccountAccess($account, $userId);
            $liability = $this->liabilities->findForUpdate($liabilityId);
            $this->assertLiabilityAccess($liability, $userId);
            if ($liability['kind'] === 'credit_card') {
                throw new DomainException('No se registran desembolsos para tarjetas de crédito.');
            }
            $this->assertSameFinancialSpace($account, $liability);
            if ($account['currency'] !== $liability['currency']) {
                throw new DomainException('El desembolso requiere una cuenta en la misma moneda que la deuda.');
            }
            if ($date < $account['initial_balance_date'] || $date < $liability['initial_balance_date']) {
                throw new DomainException('La fecha del desembolso no puede ser anterior a la fecha inicial de la deuda.');
            }
            $this->ensureDebtCanIncrease($liability, $amount);
            $this->ensureDepositIsWithinMaximum($account, $amount);

            $operationId = $this->operations->create([
                'operation_type' => 'liability_disbursement',
                'scope' => $liability['scope'],
                'owner_user_id' => $liability['owner_user_id'],
                'household_id' => $liability['household_id'],
                'created_by' => $userId,
                'category_id' => null,
                'description' => $description,
                'effective_date' => $date,
                'idempotency_key' => $key,
                'reversal_of_operation_id' => null,
            ]);
            $this->operations->addMovement($operationId, $accountId, 'inflow', $amount);
            $this->operations->addLiabilityMovement($operationId, $liabilityId, 'increase', $amount);
            $this->audit(
                $userId,
                $liability['household_id'] === null ? null : (int) $liability['household_id'],
                'finance.liability_disbursement',
                'liability',
                $liabilityId
            );
            $this->db->commit();

            return $operationId;
        } catch (PDOException $exception) {
            $this->rollback();
            if ($exception->getCode() === '23000') {
                $existingId = $this->operations->findByIdempotencyKey($userId, $key);
                if ($existingId !== null) {
                    return $existingId;
                }
            }
            throw $exception;
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    public function archive(int $userId, int $liabilityId): void
    {
        $this->db->beginTransaction();
        try {
            $liability = $this->liabilities->findForUpdate($liabilityId);
            $this->assertLiabilityAccess($liability, $userId);
            if ($liability['scope'] === 'household') {
                $role = $this->accounts->memberRole((int) $liability['household_id'], $userId, true);
                if (!$role || !in_array($role, ['owner', 'admin'], true)) {
                    throw new DomainException('Solo el propietario o un administrador puede archivar una deuda familiar.');
                }
            }
            if ($this->liabilities->balanceForLiability($liabilityId) !== '0.00') {
                throw new DomainException('Paga o ajusta el saldo pendiente antes de archivar esta deuda.');
            }
            $this->liabilities->archive($liabilityId);
            $this->audit(
                $userId,
                $liability['household_id'] === null ? null : (int) $liability['household_id'],
                'finance.liability_archived',
                'liability',
                $liabilityId
            );
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    public function setPlannedMonthlyPayment(int $userId, mixed $liabilityIdValue, mixed $amountValue): void
    {
        $liabilityId = $this->positiveId($liabilityIdValue, 'Selecciona una deuda válida.');
        $amount = $amountValue === null || $amountValue === ''
            ? null
            : DecimalAmount::normalize($amountValue);

        $this->beginFinancialTransaction();
        try {
            $liability = $this->liabilities->findForUpdate($liabilityId);
            $this->assertLiabilityAccess($liability, $userId);
            if ($liability['scope'] === 'household') {
                $role = $this->accounts->memberRole((int) $liability['household_id'], $userId, true);
                if (!$role || !in_array($role, ['owner', 'admin'], true)) {
                    throw new DomainException('Solo el propietario o un administrador puede cambiar la cuota objetivo de una deuda familiar.');
                }
            }

            $this->liabilities->setPlannedMonthlyPayment($liabilityId, $amount);
            $this->audit(
                $userId,
                $liability['household_id'] === null ? null : (int) $liability['household_id'],
                'finance.liability_payment_target_updated',
                'liability',
                $liabilityId
            );
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    private function assertLiabilityAccess(?array $liability, int $userId): void
    {
        if (!$liability || $liability['status'] !== 'active') {
            throw new DomainException('La deuda no existe o está archivada.');
        }
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

    private function assertAccountAccess(?array $account, int $userId): void
    {
        if (!$account || $account['status'] !== 'active') {
            throw new DomainException('La cuenta no existe o está archivada.');
        }
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

    private function assertSameFinancialSpace(array $account, array $liability): void
    {
        $sameSpace = $account['scope'] === $liability['scope']
            && (
                ($account['scope'] === 'personal'
                    && (int) $account['owner_user_id'] === (int) $liability['owner_user_id'])
                || ($account['scope'] === 'household'
                    && (int) $account['household_id'] === (int) $liability['household_id'])
            );
        if (!$sameSpace) {
            throw new DomainException('La cuenta y la deuda deben pertenecer al mismo espacio financiero.');
        }
    }

    private function ensureDebtCanIncrease(array $liability, string $amount): void
    {
        $balance = $this->liabilities->balanceForLiability((int) $liability['id']);
        if ($liability['kind'] === 'credit_card') {
            $statement = $this->db->prepare(
                'SELECT (
                    CAST(:balance AS DECIMAL(15,2)) + CAST(:amount AS DECIMAL(15,2))
                    <= CAST(:credit_limit AS DECIMAL(15,2))
                 )'
            );
            $statement->execute([
                'balance' => $balance,
                'amount' => $amount,
                'credit_limit' => $liability['credit_limit'],
            ]);
        } else {
            $statement = $this->db->prepare(
                'SELECT (
                    CAST(:balance AS DECIMAL(15,2)) + CAST(:amount AS DECIMAL(15,2))
                    <= 9999999999999.99
                 )'
            );
            $statement->execute(['balance' => $balance, 'amount' => $amount]);
        }

        if (!(bool) $statement->fetchColumn()) {
            throw new DomainException(
                $liability['kind'] === 'credit_card'
                    ? 'El cargo supera el cupo disponible de la tarjeta.'
                    : 'El cargo supera el saldo máximo permitido para la deuda.'
            );
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
            throw new DomainException('El abono al capital supera el saldo pendiente.');
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
        $statement->execute(['balance' => $balance, 'amount' => $amount, 'limit' => $limit]);
        if (!(bool) $statement->fetchColumn()) {
            throw new DomainException('El pago supera el saldo disponible y el sobregiro autorizado.');
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
        $statement->execute(['balance' => $balance, 'amount' => $amount]);
        if (!(bool) $statement->fetchColumn()) {
            throw new DomainException('El desembolso supera el saldo máximo permitido para la cuenta.');
        }
    }

    private function addAmounts(string $first, string $second): string
    {
        $statement = $this->db->prepare(
            'SELECT
                CAST(CAST(:first AS DECIMAL(15,2)) + CAST(:second AS DECIMAL(15,2)) AS CHAR) AS total,
                CAST(:first_check AS DECIMAL(15,2)) + CAST(:second_check AS DECIMAL(15,2))
                    <= 9999999999999.99 AS within_limit'
        );
        $statement->execute(['first' => $first, 'second' => $second, 'first_check' => $first, 'second_check' => $second]);
        $result = $statement->fetch();
        if (!$result || !(bool) $result['within_limit']) {
            throw new DomainException('El pago supera el importe máximo permitido.');
        }

        return DecimalAmount::normalize((string) $result['total']);
    }

    private function beginFinancialTransaction(): void
    {
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->db->beginTransaction();
    }

    private function operationDate(mixed $value): string
    {
        $date = DateValue::normalize($value, date('Y-m-d'));
        if ($date > date('Y-m-d')) {
            throw new DomainException('La fecha de la operación no puede estar en el futuro.');
        }

        return $date;
    }

    private function dayOfMonth(mixed $value): int
    {
        $day = filter_var($value, FILTER_VALIDATE_INT);
        if (!$day || $day < 1 || $day > 28) {
            throw new DomainException('Las fechas de corte y pago deben estar entre los días 1 y 28.');
        }

        return $day;
    }

    private function interestRate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) && !is_int($value)) {
            throw new DomainException('La tasa anual no es válida.');
        }
        $rate = (string) $value;
        if (!preg_match('/\A\d{1,3}(?:\.\d{1,3})?\z/', $rate)) {
            throw new DomainException('La tasa anual debe estar entre 0 y 100%.');
        }
        [$whole, $fraction] = array_pad(explode('.', $rate, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad($fraction, 3, '0');
        if (strlen($whole) > 3 || (strlen($whole) === 3 && ($whole > '100' || ($whole === '100' && $fraction !== '000')))) {
            throw new DomainException('La tasa anual debe estar entre 0 y 100%.');
        }

        return $whole . '.' . $fraction;
    }

    private function amountLessThanOrEqual(string $amount, string $maximum): bool
    {
        $statement = $this->db->prepare(
            'SELECT CAST(:amount AS DECIMAL(15,2)) <= CAST(:maximum AS DECIMAL(15,2))'
        );
        $statement->execute(['amount' => $amount, 'maximum' => $maximum]);

        return (bool) $statement->fetchColumn();
    }

    private function stringValue(mixed $value, string $message): string
    {
        if (!is_string($value)) {
            throw new DomainException($message);
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

    private function idempotencyKey(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\A[a-f0-9]{64}\z/', $value)) {
            throw new DomainException('Actualiza el formulario e inténtalo nuevamente.');
        }

        return $value;
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

    private function rollback(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }
}
