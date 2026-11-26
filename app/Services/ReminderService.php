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
        }

        return ['sent' => $sent, 'failed' => $failed];
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
