<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DomainException;
use App\Repositories\RecurringMovementRepository;
use App\Support\DateValue;
use App\Support\DecimalAmount;
use App\Support\RecurringSchedule;

final class RecurringMovementService
{
    public function __construct(
        private readonly RecurringMovementRepository $recurring,
        private readonly AccountService $accounts,
        private readonly OperationService $operations,
        private readonly FinancialSpaceService $spaces,
    ) {
    }

    public function listForUser(int $userId): array
    {
        $today = date('Y-m-d');
        $schedules = $this->recurring->listForUser($userId);
        foreach ($schedules as $schedule) {
            if (
                $schedule['status'] === 'active'
                && $schedule['next_due_date'] <= $today
                && $schedule['occurrence_status'] === 'confirmed'
            ) {
                $this->confirmDue($userId, (int) $schedule['id']);
            }
        }
        $schedules = $this->recurring->listForUser($userId);
        foreach ($schedules as &$schedule) {
            $schedule['due_now'] = $schedule['status'] === 'active'
                && $schedule['next_due_date'] <= $today;
            if ($schedule['due_now'] && $schedule['occurrence_status'] === null) {
                $this->recurring->ensurePendingOccurrence(
                    (int) $schedule['id'],
                    (string) $schedule['next_due_date'],
                    $userId
                );
                $schedule['occurrence_status'] = 'pending';
            }
        }
        unset($schedule);

        return $schedules;
    }

    public function create(int $userId, array $input): void
    {
        $accountId = filter_var($input['account_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$accountId || $accountId < 1) {
            throw new DomainException('Selecciona una cuenta válida.');
        }
        $account = null;
        foreach ($this->accounts->listForUser($userId, true) as $candidate) {
            if ((int) $candidate['id'] === (int) $accountId) {
                $account = $candidate;
                break;
            }
        }
        if ($account === null) {
            throw new DomainException('No tienes acceso a esa cuenta activa.');
        }

        $type = $input['operation_type'] ?? null;
        if (!is_string($type) || !in_array($type, ['income', 'expense'], true)) {
            throw new DomainException('Selecciona ingreso o gasto.');
        }
        $categoryId = filter_var($input['category_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$categoryId || $categoryId < 1) {
            throw new DomainException('Selecciona una categoría válida.');
        }
        $categoryMatches = false;
        foreach ($this->operations->categories() as $category) {
            if ((int) $category['id'] === (int) $categoryId && $category['kind'] === $type) {
                $categoryMatches = true;
                break;
            }
        }
        if (!$categoryMatches) {
            throw new DomainException('La categoría no corresponde al tipo de movimiento.');
        }

        $descriptionValue = $input['description'] ?? null;
        if (!is_string($descriptionValue)) {
            throw new DomainException('Ingresa una descripción válida.');
        }
        $description = trim($descriptionValue);
        if ($description === '' || mb_strlen($description) > 255) {
            throw new DomainException('La descripción debe tener entre 1 y 255 caracteres.');
        }

        $frequency = $input['frequency'] ?? null;
        if (!is_string($frequency) || !in_array($frequency, ['weekly', 'monthly', 'yearly'], true)) {
            throw new DomainException('Selecciona una frecuencia válida.');
        }
        $interval = filter_var($input['interval_count'] ?? null, FILTER_VALIDATE_INT);
        if (!$interval || $interval < 1 || $interval > 12) {
            throw new DomainException('La frecuencia debe repetirse entre 1 y 12 unidades.');
        }
        $startDate = DateValue::normalize($input['start_date'] ?? null, date('Y-m-d'));
        if ($startDate < $account['initial_balance_date']) {
            throw new DomainException('La programación no puede comenzar antes de la fecha inicial de la cuenta.');
        }
        $endDateValue = $input['end_date'] ?? '';
        $endDate = $endDateValue === ''
            ? null
            : DateValue::normalize($endDateValue, $startDate);
        if ($endDate !== null && $endDate < $startDate) {
            throw new DomainException('La fecha de finalización debe ser igual o posterior al inicio.');
        }

        $this->recurring->create([
            'scope' => $account['scope'],
            'owner_user_id' => $account['scope'] === 'personal' ? $userId : null,
            'household_id' => $account['scope'] === 'household' ? (int) $account['household_id'] : null,
            'account_id' => (int) $accountId,
            'operation_type' => $type,
            'category_id' => (int) $categoryId,
            'description' => $description,
            'amount' => DecimalAmount::normalize($input['amount'] ?? null),
            'frequency' => $frequency,
            'interval_count' => (int) $interval,
            'anchor_day' => (int) date('j', strtotime($startDate)),
            'start_date' => $startDate,
            'next_due_date' => $startDate,
            'end_date' => $endDate,
            'created_by' => $userId,
        ]);
    }

    public function confirmDue(int $userId, int $scheduleId, ?string $expectedDueDate = null): int
    {
        $schedule = $this->recurring->findForUser($scheduleId, $userId);
        if (!$schedule || $schedule['status'] !== 'active') {
            throw new DomainException('La programación no existe o no está activa.');
        }
        $dueDate = (string) $schedule['next_due_date'];
        if ($expectedDueDate !== null && $expectedDueDate !== $dueDate) {
            throw new DomainException('La fecha pendiente cambió. Actualiza la página antes de confirmar.');
        }
        if ($dueDate > date('Y-m-d')) {
            throw new DomainException('Este movimiento todavía no ha llegado a su fecha.');
        }

        $this->recurring->ensurePendingOccurrence($scheduleId, $dueDate, $userId);
        $occurrence = $this->recurring->occurrence($scheduleId, $dueDate);
        if (!$occurrence) {
            throw new DomainException('No se pudo preparar el movimiento pendiente.');
        }
        if ($occurrence['status'] === 'confirmed' && $occurrence['operation_id'] !== null) {
            $operationId = (int) $occurrence['operation_id'];
        } else {
            $operationId = $this->operations->createSingle($userId, [
                'operation_type' => $schedule['operation_type'],
                'account_id' => (int) $schedule['account_id'],
                'amount' => (string) $schedule['amount'],
                'category_id' => (int) $schedule['category_id'],
                'description' => (string) $schedule['description'],
                'effective_date' => $dueDate,
                'idempotency_key' => hash('sha256', 'recurring-movement:' . $scheduleId . ':' . $dueDate),
            ], (int) $schedule['created_by']);
            $this->recurring->confirmOccurrence($scheduleId, $dueDate, $operationId, $userId);
        }

        $nextDate = RecurringSchedule::nextDate(
            $dueDate,
            (string) $schedule['frequency'],
            (int) $schedule['interval_count'],
            (int) $schedule['anchor_day']
        );
        $nextStatus = $schedule['end_date'] !== null && $nextDate > $schedule['end_date']
            ? 'completed'
            : 'active';
        $this->recurring->advanceSchedule($scheduleId, $dueDate, $nextDate, $nextStatus);

        return $operationId;
    }

    public function changeStatus(int $userId, mixed $scheduleIdValue, mixed $statusValue): void
    {
        $scheduleId = filter_var($scheduleIdValue, FILTER_VALIDATE_INT);
        if (!$scheduleId || $scheduleId < 1) {
            throw new DomainException('Selecciona una programación válida.');
        }
        if (!is_string($statusValue) || !in_array($statusValue, ['active', 'paused', 'archived'], true)) {
            throw new DomainException('Selecciona un estado válido.');
        }
        $schedule = $this->recurring->findForUser((int) $scheduleId, $userId);
        if (!$schedule || !in_array($schedule['status'], ['active', 'paused'], true)) {
            throw new DomainException('La programación no existe o no se puede modificar.');
        }

        if ((int) $schedule['created_by'] !== $userId && $schedule['scope'] === 'household') {
            $space = $this->spaces->resolve($userId, 'household:' . (int) $schedule['household_id']);
            if (!in_array($space['role'], ['owner', 'admin'], true)) {
                throw new DomainException('Solo quien creó la programación o un administrador puede modificarla.');
            }
        }

        if ($statusValue === 'active' && $schedule['status'] !== 'paused') {
            throw new DomainException('Solo se puede reanudar una programación pausada.');
        }
        if ($statusValue === 'paused' && $schedule['status'] !== 'active') {
            throw new DomainException('Solo se puede pausar una programación activa.');
        }

        $this->recurring->changeStatus((int) $scheduleId, $statusValue);
    }
}
