<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class FinancialSettingsRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function findForSpace(array $space): ?array
    {
        $statement = $this->db->prepare(
            'SELECT month_start_day, budget_alert_percent, reminders_enabled,
                    reminder_days_before, timezone
             FROM financial_space_settings
             WHERE scope = :scope
               AND owner_user_id <=> :owner_user_id
               AND household_id <=> :household_id
             LIMIT 1'
        );
        $statement->bindValue(':scope', $space['scope'], PDO::PARAM_STR);
        $statement->bindValue(
            ':owner_user_id',
            $space['owner_user_id'],
            $space['owner_user_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT
        );
        $statement->bindValue(
            ':household_id',
            $space['household_id'],
            $space['household_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT
        );
        $statement->execute();
        $settings = $statement->fetch();

        return $settings ?: null;
    }

    public function saveForSpace(array $space, array $settings, int $actorId): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO financial_space_settings (
                scope, owner_user_id, household_id, month_start_day,
                budget_alert_percent, reminders_enabled, reminder_days_before,
                timezone, created_by, updated_by
             ) VALUES (
                :scope, :owner_user_id, :household_id, :month_start_day,
                :budget_alert_percent, :reminders_enabled, :reminder_days_before,
                :timezone, :created_by, :updated_by
             )
             ON DUPLICATE KEY UPDATE
                month_start_day = VALUES(month_start_day),
                budget_alert_percent = VALUES(budget_alert_percent),
                reminders_enabled = VALUES(reminders_enabled),
                reminder_days_before = VALUES(reminder_days_before),
                timezone = VALUES(timezone),
                updated_by = VALUES(updated_by)'
        );
        $statement->bindValue(':scope', $space['scope'], PDO::PARAM_STR);
        $statement->bindValue(
            ':owner_user_id',
            $space['owner_user_id'],
            $space['owner_user_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT
        );
        $statement->bindValue(
            ':household_id',
            $space['household_id'],
            $space['household_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT
        );
        $statement->bindValue(':month_start_day', $settings['month_start_day'], PDO::PARAM_INT);
        $statement->bindValue(':budget_alert_percent', $settings['budget_alert_percent'], PDO::PARAM_INT);
        $statement->bindValue(':reminders_enabled', $settings['reminders_enabled'], PDO::PARAM_INT);
        $statement->bindValue(':reminder_days_before', $settings['reminder_days_before'], PDO::PARAM_INT);
        $statement->bindValue(':timezone', $settings['timezone'], PDO::PARAM_STR);
        $statement->bindValue(':created_by', $actorId, PDO::PARAM_INT);
        $statement->bindValue(':updated_by', $actorId, PDO::PARAM_INT);
        $statement->execute();
    }

    public function enabledForReminders(): array
    {
        return $this->db->query(
            'SELECT scope, owner_user_id, household_id, space_key,
                    month_start_day, budget_alert_percent, reminder_days_before, timezone
             FROM financial_space_settings
             WHERE reminders_enabled = 1'
        )->fetchAll();
    }
}
