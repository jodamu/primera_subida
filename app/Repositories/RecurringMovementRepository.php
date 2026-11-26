<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class RecurringMovementRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function listForUser(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT s.*, a.name AS account_name, c.name AS category_name,
                    occurrence.status AS occurrence_status,
                    occurrence.operation_id AS occurrence_operation_id
             FROM recurring_movements s
             INNER JOIN accounts a ON a.id = s.account_id
             INNER JOIN categories c ON c.id = s.category_id
             LEFT JOIN recurring_movement_occurrences occurrence
               ON occurrence.schedule_id = s.id
              AND occurrence.due_date = s.next_due_date
             WHERE s.status <> "archived"
               AND (
                    (s.scope = "personal" AND s.owner_user_id = :owner_user_id)
                    OR
                    (s.scope = "household" AND EXISTS (
                        SELECT 1
                        FROM household_members hm
                        WHERE hm.household_id = s.household_id
                          AND hm.user_id = :member_user_id
                          AND hm.status = "active"
                    ))
               )
             ORDER BY FIELD(s.status, "active", "paused", "completed"),
                      s.next_due_date, s.id'
        );
        $statement->execute([
            'owner_user_id' => $userId,
            'member_user_id' => $userId,
        ]);

        return $statement->fetchAll();
    }

    public function findForUser(int $scheduleId, int $userId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT s.*
             FROM recurring_movements s
             WHERE s.id = :id
               AND (
                    (s.scope = "personal" AND s.owner_user_id = :owner_user_id)
                    OR
                    (s.scope = "household" AND EXISTS (
                        SELECT 1
                        FROM household_members hm
                        WHERE hm.household_id = s.household_id
                          AND hm.user_id = :member_user_id
                          AND hm.status = "active"
                    ))
               )
             LIMIT 1'
        );
        $statement->execute([
            'id' => $scheduleId,
            'owner_user_id' => $userId,
            'member_user_id' => $userId,
        ]);
        $schedule = $statement->fetch();

        return $schedule ?: null;
    }

    public function create(array $data): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO recurring_movements (
                scope, owner_user_id, household_id, account_id, operation_type, category_id,
                description, amount, frequency, interval_count, anchor_day, start_date, next_due_date,
                end_date, created_by
             ) VALUES (
                :scope, :owner_user_id, :household_id, :account_id, :operation_type, :category_id,
                :description, :amount, :frequency, :interval_count, :anchor_day, :start_date, :next_due_date,
                :end_date, :created_by
             )'
        );
        $statement->execute($data);

        return (int) $this->db->lastInsertId();
    }

    public function ensurePendingOccurrence(int $scheduleId, string $dueDate, int $userId): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO recurring_movement_occurrences (schedule_id, due_date, created_by)
             VALUES (:schedule_id, :due_date, :created_by)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $statement->execute([
            'schedule_id' => $scheduleId,
            'due_date' => $dueDate,
            'created_by' => $userId,
        ]);
    }

    public function occurrence(int $scheduleId, string $dueDate): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, status, operation_id
             FROM recurring_movement_occurrences
             WHERE schedule_id = :schedule_id AND due_date = :due_date
             LIMIT 1'
        );
        $statement->execute([
            'schedule_id' => $scheduleId,
            'due_date' => $dueDate,
        ]);
        $occurrence = $statement->fetch();

        return $occurrence ?: null;
    }

    public function confirmOccurrence(
        int $scheduleId,
        string $dueDate,
        int $operationId,
        int $userId
    ): void {
        $statement = $this->db->prepare(
            'UPDATE recurring_movement_occurrences
             SET status = "confirmed",
                 operation_id = :operation_id,
                 confirmed_by = :confirmed_by,
                 confirmed_at = COALESCE(confirmed_at, UTC_TIMESTAMP())
             WHERE schedule_id = :schedule_id AND due_date = :due_date
               AND status = "pending"'
        );
        $statement->execute([
            'operation_id' => $operationId,
            'confirmed_by' => $userId,
            'schedule_id' => $scheduleId,
            'due_date' => $dueDate,
        ]);
    }

    public function advanceSchedule(
        int $scheduleId,
        string $expectedDueDate,
        string $nextDueDate,
        string $status
    ): void {
        $statement = $this->db->prepare(
            'UPDATE recurring_movements
             SET next_due_date = :next_due_date, status = :status
             WHERE id = :id AND next_due_date = :expected_due_date AND status = "active"'
        );
        $statement->execute([
            'next_due_date' => $nextDueDate,
            'status' => $status,
            'id' => $scheduleId,
            'expected_due_date' => $expectedDueDate,
        ]);
    }

    public function changeStatus(int $scheduleId, string $status): void
    {
        $statement = $this->db->prepare(
            'UPDATE recurring_movements
             SET status = :status
             WHERE id = :id AND status IN ("active", "paused")'
        );
        $statement->execute([
            'status' => $status,
            'id' => $scheduleId,
        ]);
    }
}
