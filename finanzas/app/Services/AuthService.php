<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DomainException;
use App\Repositories\UserRepository;
use PDO;

final class AuthService
{
    private const MAX_ATTEMPTS = 5;
    private const LOCK_MINUTES = 15;

    public function __construct(
        private readonly PDO $db,
        private readonly UserRepository $users,
    ) {
    }

    public function register(string $name, string $email, string $password): int
    {
        $name = trim($name);
        $email = mb_strtolower(trim($email));

        if ($name === '' || mb_strlen($name) > 120) {
            throw new DomainException('Ingresa un nombre de máximo 120 caracteres.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
            throw new DomainException('Ingresa un correo electrónico válido.');
        }

        if (strlen($password) < 12 || strlen($password) > 4096) {
            throw new DomainException('La contraseña debe tener entre 12 y 4096 caracteres.');
        }

        $this->db->beginTransaction();

        try {
            $statement = $this->db->prepare(
                'INSERT INTO users (name, email, password_hash)
                 VALUES (:name, :email, :password_hash)'
            );
            $statement->execute([
                'name' => $name,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            $userId = (int) $this->db->lastInsertId();

            $household = $this->db->prepare(
                'INSERT INTO households (name, created_by)
                 VALUES (:name, :created_by)'
            );
            $household->execute([
                'name' => 'Hogar de ' . $name,
                'created_by' => $userId,
            ]);
            $householdId = (int) $this->db->lastInsertId();

            $member = $this->db->prepare(
                'INSERT INTO household_members (household_id, user_id, role)
                 VALUES (:household_id, :user_id, "owner")'
            );
            $member->execute([
                'household_id' => $householdId,
                'user_id' => $userId,
            ]);

            $this->audit($userId, $householdId, 'user.registered');
            $this->db->commit();

            return $userId;
        } catch (\PDOException $exception) {
            $this->db->rollBack();

            if ($exception->getCode() === '23000') {
                throw new DomainException('No fue posible crear la cuenta. Verifica si el correo ya está registrado.');
            }

            throw $exception;
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function authenticate(string $email, string $password, string $ipAddress): ?array
    {
        $email = mb_strtolower(trim($email));
        $attemptKey = hash('sha256', $email . "\0" . $ipAddress);

        $this->db->prepare(
            'DELETE FROM auth_attempts WHERE updated_at < UTC_TIMESTAMP() - INTERVAL 1 DAY'
        )->execute();

        $attempt = $this->db->prepare(
            'SELECT failed_attempts, locked_until
             FROM auth_attempts
             WHERE attempt_key = :attempt_key'
        );
        $attempt->execute(['attempt_key' => $attemptKey]);
        $row = $attempt->fetch();

        if ($row && $row['locked_until'] && strtotime($row['locked_until'] . ' UTC') > time()) {
            return null;
        }

        $user = $this->users->findByEmail($email);

        if (
            $user
            && $user['status'] === 'active'
            && $user['email_verified_at'] !== null
            && password_verify($password, $user['password_hash'])
        ) {
            $this->db->prepare('DELETE FROM auth_attempts WHERE attempt_key = :attempt_key')
                ->execute(['attempt_key' => $attemptKey]);
            $this->db->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = :id')
                ->execute(['id' => $user['id']]);
            $this->audit((int) $user['id'], null, 'user.logged_in');
            unset($user['password_hash'], $user['status']);

            return $user;
        }

        $this->recordFailedAttempt($attemptKey);

        return null;
    }

    private function recordFailedAttempt(string $attemptKey): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO auth_attempts (attempt_key, failed_attempts, locked_until, updated_at)
             VALUES (:attempt_key, 1, NULL, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                locked_until = IF(
                    updated_at < UTC_TIMESTAMP() - INTERVAL 15 MINUTE,
                    NULL,
                    IF(
                        failed_attempts + 1 >= ' . self::MAX_ATTEMPTS . ',
                        DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . self::LOCK_MINUTES . ' MINUTE),
                        NULL
                    )
                ),
                failed_attempts = IF(
                    updated_at < UTC_TIMESTAMP() - INTERVAL 15 MINUTE,
                    1,
                    failed_attempts + 1
                ),
                updated_at = UTC_TIMESTAMP()'
        );
        $statement->bindValue('attempt_key', $attemptKey);
        $statement->execute();
    }

    private function audit(int $userId, ?int $householdId, string $event): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO audit_log (actor_user_id, household_id, event_type)
             VALUES (:user_id, :household_id, :event_type)'
        );
        $statement->execute([
            'user_id' => $userId,
            'household_id' => $householdId,
            'event_type' => $event,
        ]);
    }
}
