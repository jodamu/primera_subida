<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\MailConfigurationException;
use App\Exceptions\MailDeliveryException;
use App\Repositories\FinancialPlanningRepository;
use App\Repositories\FinancialSettingsRepository;
use App\Support\FinancialPeriod;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use Throwable;

final class ReminderService
{
    public function __construct(
        private readonly PDO $db,
        private readonly FinancialSettingsRepository $settings,
        private readonly FinancialPlanningRepository $planning,
        private readonly MailService $mail,
        private readonly ReportService $reports,
    ) {
    }

    public function sendDue(): array
    {
        $sent = 0;
        $failed = 0;
        foreach ($this->settings->enabledForReminders() as $settings) {
            $space = [
                'scope' => $settings['scope'],
                'owner_user_id' => $settings['owner_user_id'] === null ? null : (int) $settings['owner_user_id'],
                'household_id' => $settings['household_id'] === null ? null : (int) $settings['household_id'],
            ];
            $spaceQuery = 'personal';
            if ($space['scope'] === 'household') {
                $spaceQuery = 'household:' . $space['household_id'];
            }
            $timezone = new DateTimeZone($settings['timezone']);
            $today = new DateTimeImmutable('today', $timezone);
            [$periodStart, $periodEnd] = FinancialPeriod::containing((int) $settings['month_start_day'], $today);
            $recipients = $this->recipients($space);

            foreach ($this->planning->budgetsForSpace($space, $periodStart, $periodEnd) as $budget) {
                $limitCents = (int) str_replace('.', '', (string) $budget['amount_limit']);
                $spentCents = (int) str_replace('.', '', (string) $budget['spent']);
                if ($limitCents <= 0 || $spentCents * 100 < $limitCents * (int) $settings['budget_alert_percent']) {
                    continue;
                }
                $subject = 'Aviso de presupuesto: ' . $budget['category_name'];
                $body = sprintf(
                    "El presupuesto de %s alcanzó el umbral configurado para %s.\n\nRevisa el detalle en %s/budgets?space=%s",
                    $budget['category_name'],
                    $periodStart,
                    rtrim((string) app_config('APP_URL', 'https://finanzas.jodamuover.com'), '/'),
                    rawurlencode($spaceQuery)
                );
                foreach ($recipients as $recipient) {
                    $sent += $this->sendSafely(
                        $space,
                        $recipient,
                        'budget_threshold',
                        (int) $budget['id'],
                        $periodStart,
                        $subject,
                        $body,
                        $failed
                    );
                }
            }

            if ((int) $settings['reminder_days_before'] === 0) {
                $lastReminderDate = $today->format('Y-m-d');
            } else {
                $lastReminderDate = $today->modify('+' . (int) $settings['reminder_days_before'] . ' days')->format('Y-m-d');
            }
            foreach ($this->planning->goalsForSpace($space) as $goal) {
                $targetCents = (int) str_replace('.', '', (string) $goal['target_amount']);
                $savedCents = (int) str_replace('.', '', (string) $goal['saved_amount']);
                if (
                    $goal['status'] !== 'active'
                    || $goal['target_date'] === null
                    || $savedCents >= $targetCents
                    || $goal['target_date'] < $today->format('Y-m-d')
                    || $goal['target_date'] > $lastReminderDate
                ) {
                    continue;
                }
                $subject = 'Recordatorio de meta de ahorro: ' . $goal['name'];
                $body = sprintf(
                    "La fecha objetivo de tu meta \"%s\" es %s.\n\nRevisa su progreso en %s/goals?space=%s",
                    $goal['name'],
                    $goal['target_date'],
                    rtrim((string) app_config('APP_URL', 'https://finanzas.jodamuover.com'), '/'),
                    rawurlencode($spaceQuery)
                );
                foreach ($recipients as $recipient) {
                    $sent += $this->sendSafely(
                        $space,
                        $recipient,
                        'savings_goal_deadline',
                        (int) $goal['id'],
                        (string) $goal['target_date'],
                        $subject,
                        $body,
                        $failed
                    );
                }
            }

            $baseUrl = rtrim((string) app_config('APP_URL', 'https://finanzas.jodamuover.com'), '/');
            $todayDate = $today->format('Y-m-d');

            foreach ($this->dueLiabilities($space, $today, (int) $settings['reminder_days_before']) as $due) {
                $body = sprintf(
                    "La deuda \"%s\" vence el %s.\n\nRevisa el detalle en %s/liabilities?space=%s",
                    $due['name'],
                    $due['due_date'],
                    $baseUrl,
                    rawurlencode($spaceQuery)
                );
                foreach ($recipients as $recipient) {
                    $sent += $this->sendSafely($space, $recipient, 'liability_due', (int) $due['id'], $due['due_date'], 'Vencimiento de deuda: ' . $due['name'], $body, $failed);
                }
            }

            foreach ($this->dueRecurring($space, $todayDate, $lastReminderDate) as $due) {
                $body = sprintf(
                    "El movimiento recurrente \"%s\" vence el %s.\n\nRevisa el detalle en %s/recurring?space=%s",
                    $due['description'],
                    $due['next_due_date'],
                    $baseUrl,
                    rawurlencode($spaceQuery)
                );
                foreach ($recipients as $recipient) {
                    $sent += $this->sendSafely($space, $recipient, 'recurring_due', (int) $due['id'], (string) $due['next_due_date'], 'Movimiento recurrente próximo: ' . $due['description'], $body, $failed);
                }
            }

            $weekStart = $today->modify('monday this week');
            $summary = $this->reports->totalsForSpacePeriod($space, $weekStart->modify('-7 days')->format('Y-m-d'), $weekStart->modify('-1 day')->format('Y-m-d'));
            $body = sprintf(
                "Resumen de la semana del %s al %s (solo COP, incluye compras con deuda e intereses).\n\nIngresos: %s COP\nGastos: %s COP\n\nDetalle en %s/?household_id=%s",
                $weekStart->modify('-7 days')->format('Y-m-d'),
                $weekStart->modify('-1 day')->format('Y-m-d'),
                $summary['income_total'],
                $summary['expense_total'],
                $baseUrl,
                rawurlencode($space['scope'] === 'household' ? (string) $space['household_id'] : 'personal')
            );
            foreach ($recipients as $recipient) {
                $sent += $this->sendSafely($space, $recipient, 'weekly_summary', 0, $weekStart->format('Y-m-d'), 'Tu resumen semanal de finanzas', $body, $failed);
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    private function spaceWhere(array $space, string $alias): array
    {
        if ($space['scope'] === 'personal') {
            return [$alias . '.scope = "personal" AND ' . $alias . '.owner_user_id = :space_id', (int) $space['owner_user_id']];
        }

        return [$alias . '.scope = "household" AND ' . $alias . '.household_id = :space_id', (int) $space['household_id']];
    }

    private function dueLiabilities(array $space, DateTimeImmutable $today, int $daysBefore): array
    {
        [$where, $id] = $this->spaceWhere($space, 'l');
        $statement = $this->db->prepare(
            'SELECT l.id, l.name, l.payment_due_day FROM liabilities l
             WHERE ' . $where . ' AND l.status = "active" AND l.payment_due_day IS NOT NULL'
        );
        $statement->execute(['space_id' => $id]);
        $limit = $today->modify('+' . $daysBefore . ' days');
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $day = (int) $row['payment_due_day'];
            $due = $today->setDate((int) $today->format('Y'), (int) $today->format('n'), $day);
            if ($due < $today) {
                $nextMonth = $today->modify('first day of next month');
                $due = $nextMonth->setDate(
                    (int) $nextMonth->format('Y'),
                    (int) $nextMonth->format('n'),
                    $day
                );
            }
            if ($due <= $limit) {
                $result[] = ['id' => $row['id'], 'name' => $row['name'], 'due_date' => $due->format('Y-m-d')];
            }
        }

        return $result;
    }

    private function dueRecurring(array $space, string $from, string $to): array
    {
        [$where, $id] = $this->spaceWhere($space, 'r');
        $statement = $this->db->prepare(
            'SELECT r.id, r.description, r.next_due_date FROM recurring_movements r
             WHERE ' . $where . ' AND r.status = "active" AND r.next_due_date BETWEEN :from_date AND :to_date'
        );
        $statement->execute(['space_id' => $id, 'from_date' => $from, 'to_date' => $to]);

        return $statement->fetchAll();
    }

    private function recipients(array $space): array
    {
        if ($space['scope'] === 'personal') {
            $statement = $this->db->prepare(
                'SELECT id, email FROM users
                 WHERE id = :user_id AND email_verified_at IS NOT NULL'
            );
            $statement->execute(['user_id' => $space['owner_user_id']]);
        } else {
            $statement = $this->db->prepare(
                'SELECT u.id, u.email
                 FROM users u
                 INNER JOIN household_members hm ON hm.user_id = u.id
                 WHERE hm.household_id = :household_id
                   AND hm.status = "active"
                   AND u.email_verified_at IS NOT NULL
                 ORDER BY u.id'
            );
            $statement->execute(['household_id' => $space['household_id']]);
        }

        return $statement->fetchAll();
    }

    private function sendSafely(
        array $space,
        array $recipient,
        string $referenceType,
        int $referenceId,
        string $periodStart,
        string $subject,
        string $body,
        int &$failed
    ): int {
        $this->db->beginTransaction();
        try {
            $claim = $this->db->prepare(
                'INSERT INTO reminder_deliveries (
                    scope, owner_user_id, household_id, recipient_user_id,
                    reference_type, reference_id, period_start
                 ) VALUES (
                    :scope, :owner_user_id, :household_id, :recipient_user_id,
                    :reference_type, :reference_id, :period_start
                 )'
            );
            $claim->bindValue(':scope', $space['scope'], PDO::PARAM_STR);
            $claim->bindValue(':owner_user_id', $space['owner_user_id'], $space['owner_user_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $claim->bindValue(':household_id', $space['household_id'], $space['household_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $claim->bindValue(':recipient_user_id', (int) $recipient['id'], PDO::PARAM_INT);
            $claim->bindValue(':reference_type', $referenceType, PDO::PARAM_STR);
            $claim->bindValue(':reference_id', $referenceId, PDO::PARAM_INT);
            $claim->bindValue(':period_start', $periodStart, PDO::PARAM_STR);
            $claim->execute();

            $this->mail->send((string) $recipient['email'], $subject, $body);
            $this->db->commit();

            return 1;
        } catch (PDOException $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($exception->getCode() === '23000' && (int) ($exception->errorInfo[1] ?? 0) === 1062) {
                return 0;
            }
            throw $exception;
        } catch (MailConfigurationException | MailDeliveryException $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $failed++;
            error_log('[Mi Finanzas] Recordatorio no enviado (' . $referenceType . ':' . $referenceId . ', usuario ' . (int) $recipient['id'] . '): ' . get_class($exception));

            return 0;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }
}
