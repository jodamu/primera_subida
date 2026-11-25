<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DomainException;
use App\Repositories\AccountRepository;
use App\Support\DateValue;
use App\Support\DecimalAmount;
use PDO;
use Throwable;

final class AccountService
{
    public function __construct(
        private readonly PDO $db,
        private readonly AccountRepository $accounts,
    ) {
    }

    public function types(): array
    {
        return $this->accounts->types();
    }

    public function listForUser(int $userId, bool $activeOnly = false): array
    {
        return $this->accounts->listForUser($userId, $activeOnly);
    }

    public function create(int $userId, array $input): int
    {
        $nameValue = $input['name'] ?? '';
        if (!is_string($nameValue)) {
            throw new DomainException('El nombre de la cuenta no es válido.');
        }
        $name = trim($nameValue);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new DomainException('El nombre de la cuenta debe tener entre 1 y 100 caracteres.');
        }

        $typeId = filter_var($input['account_type_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$typeId || !$this->accounts->findType((int) $typeId)) {
            throw new DomainException('Selecciona un tipo de cuenta válido.');
        }

        $scopeValue = $input['scope'] ?? '';
        if (!is_string($scopeValue)) {
            throw new DomainException('Selecciona un ámbito válido para la cuenta.');
        }
        $scope = $scopeValue;
        if (!in_array($scope, ['personal', 'household'], true)) {
            throw new DomainException('Selecciona un ámbito válido para la cuenta.');
        }

        $initialBalance = DecimalAmount::normalize($input['initial_balance'] ?? '0', true);
        $initialDate = DateValue::normalize($input['initial_balance_date'] ?? null, date('Y-m-d'));
        if ($initialDate > date('Y-m-d')) {
            throw new DomainException('La fecha del saldo inicial no puede estar en el futuro.');
        }
        $overdraftValue = $input['overdraft_enabled'] ?? null;
        if ($overdraftValue !== null && !in_array($overdraftValue, ['1', 1, true], true)) {
            throw new DomainException('La configuración de sobregiro no es válida.');
        }
        $overdraftEnabled = $overdraftValue !== null;
        $overdraftLimit = DecimalAmount::normalize($input['overdraft_limit'] ?? '0', true);

        if (!$overdraftEnabled && $overdraftLimit !== '0.00') {
            throw new DomainException('Activa el sobregiro para establecer un límite.');
        }

        $householdId = null;
        $ownerUserId = $userId;
        if ($scope === 'household') {
            $householdId = filter_var($input['household_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$householdId) {
                throw new DomainException('Selecciona un hogar válido.');
            }
            $ownerUserId = null;
        }

        $this->db->beginTransaction();

        try {
            if ($scope === 'household') {
                $role = $this->accounts->memberRole((int) $householdId, $userId, true);
                if (!$role || !in_array($role, ['owner', 'admin'], true)) {
                    throw new DomainException('Solo el propietario o un administrador puede crear cuentas familiares.');
                }
            }

            $accountId = $this->accounts->create([
                'account_type_id' => (int) $typeId,
                'name' => $name,
                'scope' => $scope,
                'owner_user_id' => $ownerUserId,
                'household_id' => $householdId,
                'created_by' => $userId,
                'initial_balance' => $initialBalance,
                'initial_balance_date' => $initialDate,
                'overdraft_enabled' => $overdraftEnabled ? 1 : 0,
                'overdraft_limit' => $overdraftLimit,
            ]);

            $this->audit($userId, $householdId, 'finance.account_created', 'account', $accountId);
            $this->db->commit();

            return $accountId;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function archive(int $userId, int $accountId): void
    {
        $this->db->beginTransaction();

        try {
            $account = $this->accounts->findForUpdate($accountId);
            if (!$account || $account['status'] !== 'active') {
                throw new DomainException('La cuenta no existe o ya está archivada.');
            }

            $householdId = null;
            if ($account['scope'] === 'personal') {
                if ((int) $account['owner_user_id'] !== $userId) {
                    throw new DomainException('No tienes permiso para modificar esta cuenta.');
                }
            } else {
                $householdId = (int) $account['household_id'];
                $role = $this->accounts->memberRole($householdId, $userId, true);
                if (!$role || !in_array($role, ['owner', 'admin'], true)) {
                    throw new DomainException('Solo el propietario o un administrador puede archivar una cuenta familiar.');
                }
            }

            $this->accounts->archive($accountId);
            $this->audit($userId, $householdId, 'finance.account_archived', 'account', $accountId);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function audit(
        int $userId,
        ?int $householdId,
        string $event,
        string $targetType,
        int $targetId
    ): void
    {
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
