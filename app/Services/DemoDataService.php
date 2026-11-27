<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DomainException;
use PDO;
use Throwable;

final class DemoDataService
{
    public function __construct(
        private readonly PDO $db,
        private readonly AccountService $accounts,
        private readonly OperationService $operations,
        private readonly LiabilityService $liabilities,
        private readonly FinancialPlanningService $planning,
        private readonly CategoryService $categories,
        private readonly CurrencyService $currencies,
        private readonly RecurringMovementService $recurring,
        private readonly string $attachmentDir,
    ) {
    }

    public function isEnabled(): bool
    {
        return (string) app_config('DEMO_DATA_ENABLED', '1') !== '0';
    }

    public function demoHouseholdFor(int $userId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT h.id, h.name, hm.role FROM households h
             INNER JOIN household_members hm ON hm.household_id = h.id AND hm.user_id = :user_id AND hm.status = "active"
             WHERE h.is_demo = 1 AND h.created_by = :creator_id LIMIT 1'
        );
        $statement->execute(['user_id' => $userId, 'creator_id' => $userId]);

        return $statement->fetch() ?: null;
    }

    public function create(int $userId): int
    {
        $this->assertEnabled();
        $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare('SELECT id FROM users WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $userId]);
            if (!$lock->fetchColumn()) {
                throw new DomainException('El usuario no está disponible.');
            }
            if ($this->demoHouseholdFor($userId) !== null) {
                throw new DomainException('Ya tienes un hogar de demostración. Elimínalo antes de crear otro.');
            }
            $this->db->prepare('INSERT INTO households (name, is_demo, created_by) VALUES ("Demostración", 1, :user_id)')
                ->execute(['user_id' => $userId]);
            $householdId = (int) $this->db->lastInsertId();
            $this->db->prepare(
                'INSERT INTO household_members (household_id, user_id, role, status) VALUES (:household_id, :user_id, "owner", "active")'
            )->execute(['household_id' => $householdId, 'user_id' => $userId]);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        try {
            $this->populate($userId, $householdId);
        } catch (Throwable $exception) {
            $this->purge($householdId);
            throw $exception;
        }

        return $householdId;
    }

    public function delete(int $userId): void
    {
        $demo = $this->demoHouseholdFor($userId);
        if ($demo === null) {
            throw new DomainException('No tienes un hogar de demostración.');
        }
        if (!in_array($demo['role'], ['owner', 'admin'], true)) {
            throw new DomainException('Solo el propietario puede eliminar la demostración.');
        }
        $this->purge((int) $demo['id']);
    }

    private function assertEnabled(): void
    {
        if (!$this->isEnabled()) {
            throw new DomainException('Los datos de demostración están desactivados en este servidor.');
        }
    }

    private function key(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function populate(int $userId, int $householdId): void
    {
        $space = 'household:' . $householdId;
        $start = date('Y-m-d', strtotime('-60 days'));
        $type = (int) $this->db->query('SELECT id FROM account_types ORDER BY id LIMIT 1')->fetchColumn();
        if ($type < 1) {
            throw new DomainException('No hay tipos de cuenta disponibles.');
        }

        $account = fn (string $name, string $currency, string $balance): int => $this->accounts->create($userId, [
            'name' => $name,
            'account_type_id' => $type,
            'scope' => 'household',
            'household_id' => $householdId,
            'currency' => $currency,
            'initial_balance' => $balance,
            'initial_balance_date' => $start,
        ]);
        $cop = $account('Demo · Cuenta COP', 'COP', '3000000');
        $usd = $account('Demo · Cuenta USD', 'USD', '500');
        $eur = $account('Demo · Cuenta EUR', 'EUR', '200');

        $this->currencies->setRate($userId, ['space' => $space, 'currency' => 'USD', 'rate' => '4000']);
        $this->currencies->setRate($userId, ['space' => $space, 'currency' => 'EUR', 'rate' => '4400']);

        $this->categories->create($userId, ['space' => $space, 'kind' => 'expense', 'name' => 'Demo · Mascotas']);
        $this->categories->create($userId, ['space' => $space, 'kind' => 'income', 'name' => 'Demo · Ventas']);

        $expenseIds = $this->db->query('SELECT id FROM categories WHERE kind = "expense" AND scope IS NULL AND is_active = 1 ORDER BY id LIMIT 3')->fetchAll(PDO::FETCH_COLUMN);
        $incomeId = (int) $this->db->query('SELECT id FROM categories WHERE kind = "income" AND scope IS NULL AND is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
        if (count($expenseIds) < 1 || $incomeId < 1) {
            throw new DomainException('No hay categorías globales disponibles.');
        }

        $this->operations->createSingle($userId, [
            'operation_type' => 'income', 'account_id' => $cop, 'amount' => '2500000', 'category_id' => $incomeId,
            'description' => 'Demo · Ingreso del mes', 'effective_date' => date('Y-m-d', strtotime('-20 days')),
            'idempotency_key' => $this->key(),
        ]);
        foreach ($expenseIds as $index => $expenseId) {
            $this->operations->createSingle($userId, [
                'operation_type' => 'expense', 'account_id' => $cop, 'amount' => (string) (120000 + $index * 45000),
                'category_id' => (int) $expenseId, 'description' => 'Demo · Gasto ' . ($index + 1),
                'effective_date' => date('Y-m-d', strtotime('-' . (5 + $index * 3) . ' days')),
                'idempotency_key' => $this->key(),
            ]);
        }
        $this->operations->transfer($userId, [
            'source_account_id' => $cop, 'destination_account_id' => $usd, 'amount' => '400000',
            'destination_amount' => '100', 'effective_date' => date('Y-m-d', strtotime('-10 days')),
            'description' => 'Demo · Compra de dólares', 'idempotency_key' => $this->key(),
        ]);
        $this->operations->transfer($userId, [
            'source_account_id' => $usd, 'destination_account_id' => $eur, 'amount' => '50',
            'destination_amount' => '45', 'effective_date' => date('Y-m-d', strtotime('-4 days')),
            'description' => 'Demo · Cambio USD a EUR', 'idempotency_key' => $this->key(),
        ]);

        $liabilityId = $this->liabilities->create($userId, [
            'kind' => 'credit_card', 'name' => 'Demo · Tarjeta', 'creditor' => 'Banco de ejemplo',
            'scope' => 'household', 'household_id' => $householdId, 'initial_balance' => '300000',
            'initial_balance_date' => $start, 'credit_limit' => '2000000',
            'statement_close_day' => '10', 'payment_due_day' => '25', 'annual_interest_rate' => '28',
        ]);
        $this->liabilities->charge($userId, [
            'liability_id' => $liabilityId, 'category_id' => (int) $expenseIds[0], 'amount' => '150000',
            'description' => 'Demo · Compra con tarjeta', 'effective_date' => date('Y-m-d', strtotime('-3 days')),
            'idempotency_key' => $this->key(),
        ]);

        $this->planning->saveBudget($userId, ['space' => $space, 'category_id' => (int) $expenseIds[0], 'amount_limit' => '200000']);
        $this->planning->createGoal($userId, [
            'space' => $space, 'name' => 'Demo · Vacaciones', 'target_amount' => '5000000',
            'target_date' => date('Y-m-d', strtotime('+120 days')),
        ]);
        $goal = $this->db->prepare('SELECT id FROM savings_goals WHERE household_id = :h ORDER BY id DESC LIMIT 1');
        $goal->execute(['h' => $householdId]);
        $this->planning->addGoalEntry($userId, [
            'space' => $space, 'goal_id' => (int) $goal->fetchColumn(), 'direction' => 'contribution',
            'amount' => '250000', 'effective_date' => date('Y-m-d'), 'description' => 'Demo · Primer ahorro',
        ]);
        $this->recurring->create($userId, [
            'account_id' => $cop, 'operation_type' => 'expense', 'category_id' => (int) $expenseIds[0],
            'description' => 'Demo · Suscripción mensual', 'amount' => '35000', 'frequency' => 'monthly',
            'interval_count' => '1', 'start_date' => date('Y-m-d', strtotime('+3 days')),
        ]);
    }

    /** Borrado completo del hogar demo; es la única excepción a la inmutabilidad del libro mayor. */
    private function purge(int $householdId): void
    {
        $opIds = 'SELECT id FROM operations WHERE household_id = :h';
        $accountIds = 'SELECT id FROM accounts WHERE household_id = :h';
        $statements = [
            "DELETE FROM operation_attachments WHERE operation_id IN ($opIds)",
            "DELETE FROM transfers WHERE operation_id IN ($opIds)",
            "DELETE FROM liability_movements WHERE operation_id IN ($opIds)",
            "DELETE FROM csv_import_rows WHERE batch_id IN (SELECT id FROM csv_import_batches WHERE account_id IN ($accountIds))",
            'DELETE FROM csv_import_batches WHERE account_id IN (' . $accountIds . ')',
            "DELETE FROM reconciliation_rows WHERE batch_id IN (SELECT id FROM reconciliation_batches WHERE account_id IN ($accountIds))",
            'DELETE FROM reconciliation_batches WHERE account_id IN (' . $accountIds . ')',
            'DELETE FROM recurring_movement_occurrences WHERE schedule_id IN (SELECT id FROM recurring_movements WHERE household_id = :h)',
            'DELETE FROM recurring_movements WHERE household_id = :h',
            "DELETE FROM movements WHERE operation_id IN ($opIds)",
            'UPDATE operations SET reversal_of_operation_id = NULL WHERE household_id = :h',
            'DELETE FROM operations WHERE household_id = :h',
            'DELETE FROM liabilities WHERE household_id = :h',
            'DELETE FROM accounts WHERE household_id = :h',
            'DELETE FROM savings_goal_entries WHERE goal_id IN (SELECT id FROM savings_goals WHERE household_id = :h)',
            'DELETE FROM savings_goals WHERE household_id = :h',
            'DELETE FROM budgets WHERE household_id = :h',
            'DELETE FROM financial_space_settings WHERE household_id = :h',
            'DELETE FROM exchange_rates WHERE household_id = :h',
            'DELETE FROM reminder_deliveries WHERE household_id = :h',
            'DELETE FROM categories WHERE household_id = :h',
            'DELETE FROM backup_restore_records WHERE target_scope = "household" AND target_space_id = :h',
            'DELETE FROM invitations WHERE household_id = :h',
            'DELETE FROM audit_log WHERE household_id = :h',
            'DELETE FROM household_members WHERE household_id = :h',
            'DELETE FROM households WHERE id = :h AND is_demo = 1',
        ];

        $storedNames = [];
        try {
            $this->db->beginTransaction();
            $isDemo = $this->db->prepare('SELECT id FROM households WHERE id = :id AND is_demo = 1 FOR UPDATE');
            $isDemo->execute(['id' => $householdId]);
            if (!$isDemo->fetchColumn()) {
                throw new DomainException('Solo se pueden eliminar hogares de demostración.');
            }
            $files = $this->db->prepare("SELECT stored_name FROM operation_attachments WHERE operation_id IN ($opIds)");
            $files->execute(['h' => $householdId]);
            $storedNames = $files->fetchAll(PDO::FETCH_COLUMN);
            foreach ($statements as $sql) {
                $this->db->prepare($sql)->execute(['h' => $householdId]);
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
        foreach ($storedNames as $storedName) {
            $path = $this->attachmentDir . DIRECTORY_SEPARATOR . $storedName;
            if (is_file($path) && !unlink($path)) {
                error_log('[Mi Finanzas] No se pudo eliminar un archivo del hogar demo ' . $householdId);
                throw new DomainException('Se eliminó el hogar demo, pero quedó un archivo por limpiar en el servidor.');
            }
        }
    }
}
