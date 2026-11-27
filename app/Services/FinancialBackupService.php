<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class FinancialBackupService
{
    public function __construct(
        private readonly PDO $db,
        private readonly HouseholdService $households,
    ) {
    }

    public function forUser(int $userId): array
    {
        $this->db->beginTransaction();
        try {
            $backup = $this->buildForUser($userId);
            $this->db->commit();

            return $backup;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function buildForUser(int $userId): array
    {
        $userHouseholds = $this->households->forUser($userId);
        $householdIds = array_map(
            static fn (array $household): int => (int) $household['id'],
            $userHouseholds
        );
        [$personalAndHousehold, $scopeParams] = $this->scopeCondition('record', $userId, $householdIds);
        [$accountScope, $accountParams] = $this->scopeCondition('account', $userId, $householdIds);
        [$operationScope, $operationParams] = $this->scopeCondition('operation', $userId, $householdIds);
        [$liabilityScope, $liabilityParams] = $this->scopeCondition('liability', $userId, $householdIds);
        [$goalScope, $goalParams] = $this->scopeCondition('goal', $userId, $householdIds);
        [$scheduleScope, $scheduleParams] = $this->scopeCondition('schedule', $userId, $householdIds);

        return [
            'format_version' => 2,
            'source_origin_id' => $this->originId(),
            'generated_at' => gmdate('c'),
            'note' => 'Incluye los datos personales y los hogares a los que tienes acceso. No incluye contraseñas, tokens ni secretos de configuración.',
            'financial_spaces' => array_merge(
                [['scope' => 'personal', 'source_id' => null, 'name' => 'Personal']],
                array_map(
                    static fn (array $household): array => [
                        'scope' => 'household',
                        'source_id' => (int) $household['id'],
                        'name' => (string) $household['name'],
                    ],
                    $userHouseholds
                )
            ),
            'data' => [
                'account_types' => $this->rows('SELECT id, code, name, sort_order, is_active FROM account_types ORDER BY id'),
                'categories' => $this->rows(
                    'SELECT record.id, record.code, record.name, record.kind, record.scope,
                            record.owner_user_id, record.household_id, record.sort_order, record.is_active
                     FROM categories record
                     WHERE record.scope IS NULL OR (' . $personalAndHousehold . ')
                     ORDER BY record.id',
                    $scopeParams
                ),
                'accounts' => $this->rows(
                    'SELECT record.id, record.account_type_id, record.name, record.scope,
                            record.owner_user_id, record.household_id, record.created_by,
                            record.initial_balance, record.initial_balance_date, record.currency,
                            record.overdraft_enabled, record.overdraft_limit, record.status,
                            record.created_at, record.archived_at
                     FROM accounts record
                     WHERE ' . $personalAndHousehold . '
                     ORDER BY record.id',
                    $scopeParams
                ),
                'operations' => $this->rows(
                    'SELECT record.id, record.operation_type, record.status, record.scope,
                            record.owner_user_id, record.household_id, record.created_by,
                            record.category_id, record.description, record.effective_date,
                            record.reversal_of_operation_id, record.created_at, record.confirmed_at
                     FROM operations record
                     WHERE ' . $personalAndHousehold . '
                     ORDER BY record.id',
                    $scopeParams
                ),
                'movements' => $this->rows(
                    'SELECT movement.id, movement.operation_id, movement.account_id,
                            movement.direction, movement.amount, movement.created_at
                     FROM movements movement
                     INNER JOIN accounts account ON account.id = movement.account_id
                     WHERE ' . $accountScope . '
                     ORDER BY movement.id',
                    $accountParams
                ),
                'transfers' => $this->rows(
                    'SELECT transfer.operation_id, transfer.source_movement_id, transfer.destination_movement_id
                     FROM transfers transfer
                     INNER JOIN operations operation ON operation.id = transfer.operation_id
                     WHERE ' . $operationScope . '
                     ORDER BY transfer.operation_id',
                    $operationParams
                ),
                'liabilities' => $this->rows(
                    'SELECT record.id, record.kind, record.name, record.creditor, record.scope,
                            record.owner_user_id, record.household_id, record.created_by,
                            record.initial_balance, record.initial_balance_date, record.credit_limit,
                            record.statement_close_day, record.payment_due_day, record.annual_interest_rate,
                            record.planned_monthly_payment,
                            record.currency, record.status, record.created_at, record.archived_at
                     FROM liabilities record
                     WHERE ' . $personalAndHousehold . '
                     ORDER BY record.id',
                    $scopeParams
                ),
                'liability_movements' => $this->rows(
                    'SELECT movement.id, movement.liability_id, movement.operation_id,
                            movement.direction, movement.amount, movement.interest_amount, movement.created_at
                     FROM liability_movements movement
                     INNER JOIN liabilities liability ON liability.id = movement.liability_id
                     WHERE ' . $liabilityScope . '
                     ORDER BY movement.id',
                    $liabilityParams
                ),
                'financial_space_settings' => $this->rows(
                    'SELECT record.id, record.scope, record.owner_user_id, record.household_id,
                            record.month_start_day, record.budget_alert_percent, record.reminders_enabled,
                            record.reminder_days_before, record.timezone, record.created_at, record.updated_at
                     FROM financial_space_settings record
                     WHERE ' . $personalAndHousehold . '
                     ORDER BY record.id',
                    $scopeParams
                ),
                'budgets' => $this->rows(
                    'SELECT record.id, record.scope, record.owner_user_id, record.household_id,
                            record.category_id, record.period_start, record.period_end, record.amount_limit,
                            record.created_by, record.updated_by, record.created_at, record.updated_at
                     FROM budgets record
                     WHERE ' . $personalAndHousehold . '
                     ORDER BY record.id',
                    $scopeParams
                ),
                'savings_goals' => $this->rows(
                    'SELECT record.id, record.scope, record.owner_user_id, record.household_id,
                            record.name, record.target_amount, record.target_date, record.status,
                            record.created_by, record.created_at, record.archived_at
                     FROM savings_goals record
                     WHERE ' . $personalAndHousehold . '
                     ORDER BY record.id',
                    $scopeParams
                ),
                'savings_goal_entries' => $this->rows(
                    'SELECT entry.id, entry.goal_id, entry.created_by, entry.direction,
                            entry.amount, entry.effective_date, entry.description, entry.created_at
                     FROM savings_goal_entries entry
                     INNER JOIN savings_goals goal ON goal.id = entry.goal_id
                     WHERE ' . $goalScope . '
                     ORDER BY entry.id',
                    $goalParams
                ),
                'recurring_movements' => $this->rows(
                    'SELECT record.id, record.scope, record.owner_user_id, record.household_id,
                            record.account_id, record.operation_type, record.category_id, record.description,
                            record.amount, record.frequency, record.interval_count, record.anchor_day,
                            record.start_date, record.next_due_date, record.end_date, record.status,
                            record.created_by, record.created_at, record.updated_at
                     FROM recurring_movements record
                     WHERE ' . $personalAndHousehold . '
                     ORDER BY record.id',
                    $scopeParams
                ),
                'recurring_movement_occurrences' => $this->rows(
                    'SELECT occurrence.id, occurrence.schedule_id, occurrence.due_date, occurrence.status,
                            occurrence.operation_id, occurrence.created_by, occurrence.confirmed_by,
                            occurrence.created_at, occurrence.confirmed_at
                     FROM recurring_movement_occurrences occurrence
                     INNER JOIN recurring_movements schedule ON schedule.id = occurrence.schedule_id
                     WHERE ' . $scheduleScope . '
                     ORDER BY occurrence.id',
                    $scheduleParams
                ),
            ],
        ];
    }

    private function scopeCondition(string $alias, int $userId, array $householdIds): array
    {
        $condition = '(' . $alias . '.scope = "personal" AND ' . $alias . '.owner_user_id = :backup_owner)';
        $params = ['backup_owner' => $userId];
        if ($householdIds) {
            $placeholders = [];
            foreach ($householdIds as $index => $householdId) {
                $placeholder = 'backup_household_' . $index;
                $placeholders[] = ':' . $placeholder;
                $params[$placeholder] = $householdId;
            }
            $condition = '(' . $condition . ' OR (' . $alias . '.scope = "household" AND '
                . $alias . '.household_id IN (' . implode(', ', $placeholders) . ')))';
        }

        return [$condition, $params];
    }

    private function rows(string $sql, array $params = []): array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    private function originId(): string
    {
        $statement = $this->db->prepare(
            'INSERT INTO financial_backup_origin (origin_key, origin_id)
             VALUES (1, :origin_id)
             ON DUPLICATE KEY UPDATE origin_id = origin_id'
        );
        $statement->execute(['origin_id' => bin2hex(random_bytes(16))]);
        $originId = $this->db->query(
            'SELECT origin_id FROM financial_backup_origin WHERE origin_key = 1'
        )->fetchColumn();
        if (!is_string($originId) || !preg_match('/\A[a-f0-9]{32}\z/', $originId)) {
            throw new \RuntimeException('No fue posible obtener el identificador de origen del respaldo.');
        }

        return $originId;
    }
}
