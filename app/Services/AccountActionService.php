<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DomainException;
use App\Exceptions\MailConfigurationException;
use App\Exceptions\MailDeliveryException;
use App\Repositories\UserRepository;
use PDO;
use Throwable;

final class AccountActionService
{
    public function __construct(
        private readonly PDO $db,
        private readonly UserRepository $users,
        private readonly MailService $mail,
    ) {
    }

    public function sendVerificationForEmail(string $email): void
    {
        $user = $this->users->findByEmail(mb_strtolower(trim($email)));
        if (!$user || $user['status'] !== 'active' || $user['email_verified_at'] !== null) {
            return;
        }

        $this->sendToken((int) $user['id'], 'verify_email');
    }

    public function requestPasswordReset(string $email): void
    {
        $user = $this->users->findByEmail(mb_strtolower(trim($email)));
        if (!$user || $user['status'] !== 'active' || $user['email_verified_at'] === null) {
            return;
        }

        $this->sendToken((int) $user['id'], 'reset_password');
    }

    public function verifyEmail(string $token): void
    {
        $this->consumeToken($token, 'verify_email');
    }

    public function resetPassword(string $token, string $password): void
    {
        if (strlen($password) < 12 || strlen($password) > 4096) {
            throw new DomainException('La contraseña debe tener entre 12 y 4096 caracteres.');
        }

        $this->db->beginTransaction();
        try {
            $tokenRow = $this->lockToken($token, 'reset_password');
            $statement = $this->db->prepare(
                'UPDATE users
                 SET password_hash = :password_hash,
                     auth_version = auth_version + 1
                 WHERE id = :id AND status = "active"'
            );
            $statement->execute([
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'id' => $tokenRow['user_id'],
            ]);
            if ($statement->rowCount() !== 1) {
                throw new DomainException('El enlace no es válido o ya venció. Solicita uno nuevo.');
            }

            $this->consumeUserTokens((int) $tokenRow['user_id'], 'reset_password');
            $this->audit((int) $tokenRow['user_id'], 'user.password_reset');
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function sendToken(int $userId, string $purpose): void
    {
        $baseUrl = rtrim((string) app_config('APP_URL', ''), '/');
        $urlParts = parse_url($baseUrl);
        if (
            !is_array($urlParts)
            || ($urlParts['scheme'] ?? null) !== 'https'
            || empty($urlParts['host'])
            || isset($urlParts['user'])
            || isset($urlParts['pass'])
            || !in_array($urlParts['path'] ?? '', ['', '/'], true)
            || isset($urlParts['query'])
            || isset($urlParts['fragment'])
        ) {
            throw new MailConfigurationException('APP_URL debe ser una URL HTTPS válida.');
        }
        $this->mail->assertConfigured();

        $this->db->beginTransaction();
        try {
            $userStatement = $this->db->prepare(
                'SELECT id, email, name
                 FROM users
                 WHERE id = :id AND status = "active"
                 LIMIT 1
                 FOR UPDATE'
            );
            $userStatement->execute(['id' => $userId]);
            $user = $userStatement->fetch();
            if (!$user) {
                $this->db->rollBack();
                return;
            }

            $recent = $this->db->prepare(
                'SELECT 1
                 FROM account_action_tokens
                 WHERE user_id = :user_id
                   AND purpose = :purpose
                   AND consumed_at IS NULL
                   AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 MINUTE
                 LIMIT 1'
            );
            $recent->execute(['user_id' => $userId, 'purpose' => $purpose]);
            if ($recent->fetchColumn()) {
                $this->db->commit();
                return;
            }

            $this->db->exec(
                'DELETE FROM account_action_tokens
                 WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 30 DAY'
            );
            $this->consumeUserTokens($userId, $purpose);
            $token = bin2hex(random_bytes(32));
            $expiry = $purpose === 'verify_email'
                ? 'DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR)'
                : 'DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR)';
            $insert = $this->db->prepare(
                'INSERT INTO account_action_tokens (user_id, purpose, token_hash, expires_at, created_at)
                 VALUES (:user_id, :purpose, :token_hash, ' . $expiry . ', UTC_TIMESTAMP())'
            );
            $insert->execute([
                'user_id' => $userId,
                'purpose' => $purpose,
                'token_hash' => hash('sha256', $token),
            ]);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        $path = $purpose === 'verify_email' ? '/email/verify' : '/password/reset';
        $subject = $purpose === 'verify_email' ? 'Verifica tu correo de Mi Finanzas' : 'Restablece tu contraseña de Mi Finanzas';
        $instructions = $purpose === 'verify_email'
            ? 'Confirma tu correo para activar el acceso a Mi Finanzas.'
            : 'Usa este enlace para crear una nueva contraseña. Si no solicitaste el cambio, ignora este correo.';
        try {
            $this->mail->send(
                $user['email'],
                $subject,
                "Hola {$user['name']},\n\n{$instructions}\n\n{$baseUrl}{$path}?token={$token}\n\nSi no solicitaste esta acción, puedes ignorar este mensaje."
            );
        } catch (MailDeliveryException $exception) {
            $invalidate = $this->db->prepare(
                'UPDATE account_action_tokens
                 SET consumed_at = UTC_TIMESTAMP()
                 WHERE token_hash = :token_hash AND consumed_at IS NULL'
            );
            $invalidate->execute(['token_hash' => hash('sha256', $token)]);
            throw $exception;
        }
    }

    private function consumeToken(string $token, string $purpose): int
    {
        $this->db->beginTransaction();
        try {
            $tokenRow = $this->lockToken($token, $purpose);
            $this->consumeUserTokens((int) $tokenRow['user_id'], $purpose);
            if ($purpose === 'verify_email') {
                $statement = $this->db->prepare(
                    'UPDATE users
                     SET email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP())
                     WHERE id = :id AND status = "active"'
                );
                $statement->execute(['id' => $tokenRow['user_id']]);
                if ($statement->rowCount() !== 1) {
                    throw new DomainException('El enlace no es válido o ya venció. Solicita uno nuevo.');
                }
                $this->audit((int) $tokenRow['user_id'], 'user.email_verified');
            }
            $this->db->commit();

            return (int) $tokenRow['user_id'];
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function lockToken(string $token, string $purpose): array
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            throw new DomainException('El enlace no es válido o ya venció. Solicita uno nuevo.');
        }

        $statement = $this->db->prepare(
            'SELECT id, user_id
             FROM account_action_tokens
             WHERE token_hash = :token_hash
               AND purpose = :purpose
               AND consumed_at IS NULL
               AND expires_at > UTC_TIMESTAMP()
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute([
            'token_hash' => hash('sha256', $token),
            'purpose' => $purpose,
        ]);
        $row = $statement->fetch();
        if (!$row) {
            throw new DomainException('El enlace no es válido o ya venció. Solicita uno nuevo.');
        }

        return $row;
    }

    private function consumeUserTokens(int $userId, string $purpose): void
    {
        $statement = $this->db->prepare(
            'UPDATE account_action_tokens
             SET consumed_at = UTC_TIMESTAMP()
             WHERE user_id = :user_id AND purpose = :purpose AND consumed_at IS NULL'
        );
        $statement->execute(['user_id' => $userId, 'purpose' => $purpose]);
    }

    private function audit(int $userId, string $event): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO audit_log (actor_user_id, event_type)
             VALUES (:user_id, :event_type)'
        );
        $statement->execute(['user_id' => $userId, 'event_type' => $event]);
    }
}
