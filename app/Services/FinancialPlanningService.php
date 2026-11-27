<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DomainException;
use App\Repositories\FinancialPlanningRepository;
use App\Support\DateValue;
use App\Support\DecimalAmount;
use App\Support\FinancialPeriod;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;

final class FinancialPlanningService
{
    public function __construct(
        private readonly PDO $db,
        private readonly FinancialSpaceService $spaces,
        private readonly FinancialSettingsService $settings,
        private readonly FinancialPlanningRepository $planning,
    ) {
    }

    public function budgets(int $userId, mixed $spaceKey): array
    {
        $configuration = $this->settings->get($userId, $spaceKey);
        [$start, $end] = FinancialPeriod::containing(
            (int) $configuration['settings']['month_start_day'],
            new DateTimeImmutable('now', new DateTimeZone($configuration['settings']['timezone']))
        );

        return [
            'space' => $configuration['space'],
            'can_manage' => $configuration['can_manage'],
            'period_start' => $start,
            'period_end' => $end,
            'alert_percent' => (int) $configuration['settings']['budget_alert_percent'],
            'budgets' => $this->planning->budgetsForSpace($configuration['space'], $start, $end),
            'categories' => $this->planning->expenseCategories($configuration['space']),
        ];
    }

    public function saveBudget(int $userId, array $input): void
    {
        $space = $this->spaces->resolve($userId, $input['space'] ?? null, true);
        $categoryId = filter_var($input['category_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$categoryId || $categoryId < 1) {
            throw new DomainException('Selecciona una categoría de gasto válida.');
        }
        $category = null;
        foreach ($this->planning->expenseCategories($space) as $candidate) {
            if ((int) $candidate['id'] === (int) $categoryId) {
                $category = $candidate;
                break;
            }
        }
        if ($category === null) {
            throw new DomainException('Selecciona una categoría de gasto válida.');
        }
        $amount = DecimalAmount::normalize($input['amount_limit'] ?? null);
        $configuration = $this->settings->get($userId, $space['key']);
        [$start, $end] = FinancialPeriod::containing(
            (int) $configuration['settings']['month_start_day'],
            new DateTimeImmutable('now', new DateTimeZone($configuration['settings']['timezone']))
        );

        $this->planning->saveBudget($space, (int) $categoryId, $start, $end, $amount, $userId);
    }

    public function goals(int $userId, mixed $spaceKey): array
    {
        $space = $this->spaces->resolve($userId, $spaceKey);

        return [
            'space' => $space,
            'can_manage' => $space['scope'] === 'personal' || in_array($space['role'], ['owner', 'admin'], true),
            'goals' => $this->planning->goalsForSpace($space),
        ];
    }

    public function createGoal(int $userId, array $input): void
    {
        $space = $this->spaces->resolve($userId, $input['space'] ?? null);
        $name = $input['name'] ?? null;
        if (!is_string($name)) {
            throw new DomainException('Ingresa un nombre válido para la meta.');
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new DomainException('El nombre de la meta debe tener entre 1 y 120 caracteres.');
        }
        $targetAmount = DecimalAmount::normalize($input['target_amount'] ?? null);
        $targetDateValue = $input['target_date'] ?? '';
        $targetDate = null;
        if ($targetDateValue !== '') {
            $targetDate = DateValue::normalize($targetDateValue, date('Y-m-d'));
            if ($targetDate < date('Y-m-d')) {
                throw new DomainException('La fecha objetivo no puede estar en el pasado.');
            }
        }
        $this->planning->createGoal($space, $name, $targetAmount, $targetDate, $userId);
    }

    public function addGoalEntry(int $userId, array $input): void
    {
        $space = $this->spaces->resolve($userId, $input['space'] ?? null);
        $goalId = filter_var($input['goal_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$goalId || $goalId < 1) {
            throw new DomainException('Selecciona una meta válida.');
        }
        $direction = $input['direction'] ?? null;
        if (!in_array($direction, ['contribution', 'withdrawal'], true)) {
            throw new DomainException('Selecciona un tipo de aporte válido.');
        }
        $amount = DecimalAmount::normalize($input['amount'] ?? null);
        $date = DateValue::normalize($input['effective_date'] ?? null, date('Y-m-d'));
        if ($date > date('Y-m-d')) {
            throw new DomainException('La fecha del aporte no puede estar en el futuro.');
        }
        $description = $input['description'] ?? '';
        if (!is_string($description)) {
            throw new DomainException('Ingresa una descripción válida.');
        }
        $description = trim($description);
        if ($description === '' || mb_strlen($description) > 255) {
            throw new DomainException('La descripción debe tener entre 1 y 255 caracteres.');
        }
        $key = $input['idempotency_key'] ?? null;
        if (!is_string($key) || !preg_match('/\A[a-f0-9]{64}\z/', $key)) {
            throw new DomainException('La solicitud venció; actualiza la página e inténtalo de nuevo.');
        }

        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->db->beginTransaction();
        try {
            if ($this->planning->goalEntryExists($userId, $key)) {
                $this->db->commit();
                return;
            }
            $goal = $this->planning->goalForUpdate((int) $goalId, $space);
            if (!$goal || $goal['status'] !== 'active') {
                throw new DomainException('La meta no existe o está archivada.');
            }
            if ($direction === 'withdrawal') {
                $balance = $this->planning->goalBalance((int) $goalId);
                if ((int) str_replace('.', '', $amount) > (int) str_replace('.', '', $balance)) {
                    throw new DomainException('El retiro supera el saldo acumulado de esta meta.');
                }
            }
            $this->planning->addGoalEntry(
                (int) $goalId,
                $userId,
                $direction,
                $amount,
                $date,
                $description,
                $key
            );
            $this->db->commit();
        } catch (PDOException $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($exception->getCode() === '23000' && $this->planning->goalEntryExists($userId, $key)) {
                return;
            }
            throw $exception;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function archiveGoal(int $userId, mixed $spaceKey, mixed $goalId): void
    {
        $space = $this->spaces->resolve($userId, $spaceKey, true);
        $id = filter_var($goalId, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            throw new DomainException('Selecciona una meta válida.');
        }
        $this->planning->archiveGoal((int) $id, $space);
    }
}
