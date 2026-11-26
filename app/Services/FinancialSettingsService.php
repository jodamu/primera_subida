<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DomainException;
use App\Repositories\FinancialSettingsRepository;
use DateTimeZone;

final class FinancialSettingsService
{
    private const DEFAULTS = [
        'month_start_day' => 1,
        'budget_alert_percent' => 80,
        'reminders_enabled' => 0,
        'reminder_days_before' => 3,
        'timezone' => 'America/Bogota',
    ];

    public function __construct(
        private readonly FinancialSpaceService $spaces,
        private readonly FinancialSettingsRepository $settings,
    ) {
    }

    public function get(int $userId, mixed $spaceKey): array
    {
        $space = $this->spaces->resolve($userId, $spaceKey);

        return [
            'space' => $space,
            'settings' => array_merge(
                self::DEFAULTS,
                $this->settings->findForSpace($space) ?? []
            ),
            'can_manage' => $space['scope'] === 'personal'
                || in_array($space['role'], ['owner', 'admin'], true),
        ];
    }

    public function save(int $userId, mixed $spaceKey, array $input): void
    {
        $space = $this->spaces->resolve($userId, $spaceKey, true);
        $monthStartDay = $this->boundedInteger($input['month_start_day'] ?? null, 1, 28, 'El día de inicio debe estar entre 1 y 28.');
        $alertPercent = $this->boundedInteger($input['budget_alert_percent'] ?? null, 1, 100, 'El umbral de presupuesto debe estar entre 1 y 100%.');
        $reminderDays = $this->boundedInteger($input['reminder_days_before'] ?? null, 0, 30, 'Los recordatorios deben configurarse entre 0 y 30 días.');
        $remindersEnabled = $input['reminders_enabled'] ?? null;
        if (!in_array($remindersEnabled, ['1', 1], true)) {
            $remindersEnabled = 0;
        } else {
            $remindersEnabled = 1;
        }

        $timezone = $input['timezone'] ?? null;
        if (!is_string($timezone) || strlen($timezone) > 64) {
            throw new DomainException('Selecciona una zona horaria válida.');
        }
        try {
            new DateTimeZone($timezone);
        } catch (\Exception) {
            throw new DomainException('Selecciona una zona horaria válida.');
        }

        $this->settings->saveForSpace(
            $space,
            [
                'month_start_day' => $monthStartDay,
                'budget_alert_percent' => $alertPercent,
                'reminders_enabled' => $remindersEnabled,
                'reminder_days_before' => $reminderDays,
                'timezone' => $timezone,
            ],
            $userId
        );
    }

    private function boundedInteger(mixed $value, int $minimum, int $maximum, string $message): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer < $minimum || $integer > $maximum) {
            throw new DomainException($message);
        }

        return (int) $integer;
    }
}
