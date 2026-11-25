<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DomainException;
use PDO;

final class HouseholdService
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function forUser(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT h.id, h.name, hm.role
             FROM households h
             INNER JOIN household_members hm ON hm.household_id = h.id
             WHERE hm.user_id = :user_id AND hm.status = "active"
             ORDER BY h.id'
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll();
    }

    public function membersForManagement(int $householdId, int $actorId): array
    {
        $role = $this->memberRole($householdId, $actorId);
        if (!$role || !in_array($role, ['owner', 'admin'], true)) {
            throw new DomainException('No tienes permiso para administrar los integrantes de este hogar.');
        }

        $statement = $this->db->prepare(
            'SELECT hm.id AS member_id, hm.user_id, hm.role, hm.status, hm.joined_at,
                    u.name, u.email
             FROM household_members hm
             INNER JOIN users u ON u.id = hm.user_id
             WHERE hm.household_id = :household_id
             ORDER BY FIELD(hm.role, "owner", "admin", "member"), hm.joined_at, hm.id'
        );
        $statement->execute(['household_id' => $householdId]);

        return $statement->fetchAll();
    }

    public function changeMemberRole(int $householdId, int $memberId, int $actorId, string $role): void
    {
        if (!in_array($role, ['admin', 'member'], true)) {
            throw new DomainException('Selecciona un rol válido.');
        }
        $this->db->beginTransaction();
        try {
            $actorRole = $this->memberRole($householdId, $actorId, true);
            if ($actorRole !== 'owner') {
                throw new DomainException('Solo el propietario puede cambiar los roles.');
            }
            $target = $this->lockMember($householdId, $memberId);
            if (!$target || $target['role'] === 'owner' || (int) $target['user_id'] === $actorId) {
                throw new DomainException('No puedes cambiar el rol del propietario o de tu propia cuenta.');
            }

            $statement = $this->db->prepare(
                'UPDATE household_members SET role = :role WHERE id = :id'
            );
            $statement->execute(['role' => $role, 'id' => $memberId]);
            $this->audit($actorId, $householdId, 'household.member_role_changed', 'household_member', $memberId);
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function setMemberStatus(int $householdId, int $memberId, int $actorId, string $status): void
    {
        if (!in_array($status, ['active', 'suspended'], true)) {
            throw new DomainException('Selecciona un estado válido.');
        }
        $this->db->beginTransaction();
        try {
            $actorRole = $this->memberRole($householdId, $actorId, true);
            if ($actorRole !== 'owner') {
                throw new DomainException('Solo el propietario puede suspender o reactivar integrantes.');
            }
            $target = $this->lockMember($householdId, $memberId);
            if (!$target || $target['role'] === 'owner' || (int) $target['user_id'] === $actorId) {
                throw new DomainException('No puedes cambiar el estado del propietario o de tu propia cuenta.');
            }
            if (!in_array($target['status'], ['active', 'suspended'], true)) {
                throw new DomainException('No puedes reactivar a una persona que salió del hogar.');
            }

            $statement = $this->db->prepare(
                'UPDATE household_members SET status = :status WHERE id = :id'
            );
            $statement->execute(['status' => $status, 'id' => $memberId]);
            $this->audit(
                $actorId,
                $householdId,
                $status === 'suspended' ? 'household.member_suspended' : 'household.member_reactivated',
                'household_member',
                $memberId
            );
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function createInvitation(int $householdId, int $actorId, string $email): string
    {
        $email = mb_strtolower(trim($email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
            throw new DomainException('Ingresa un correo electrónico válido.');
        }

        $access = $this->db->prepare(
            'SELECT role
             FROM household_members
             WHERE household_id = :household_id AND user_id = :user_id AND status = "active"
             LIMIT 1'
        );
        $access->execute(['household_id' => $householdId, 'user_id' => $actorId]);
        $member = $access->fetch();

        if (!$member || !in_array($member['role'], ['owner', 'admin'], true)) {
            throw new DomainException('No tienes permiso para invitar integrantes a este hogar.');
        }

        $existing = $this->db->prepare(
            'SELECT id FROM users WHERE email = :email LIMIT 1'
        );
        $existing->execute(['email' => $email]);
        $user = $existing->fetch();

        if ($user) {
            $membership = $this->db->prepare(
                'SELECT id FROM household_members
                 WHERE household_id = :household_id AND user_id = :user_id AND status = "active"'
            );
            $membership->execute(['household_id' => $householdId, 'user_id' => $user['id']]);

            if ($membership->fetch()) {
                throw new DomainException('Ese usuario ya pertenece a este hogar.');
            }
        }

        $token = bin2hex(random_bytes(32));
        $statement = $this->db->prepare(
            'INSERT INTO invitations (household_id, invited_by, email, token_hash, expires_at)
             VALUES (:household_id, :invited_by, :email, :token_hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY))'
        );
        $statement->execute([
            'household_id' => $householdId,
            'invited_by' => $actorId,
            'email' => $email,
            'token_hash' => hash('sha256', $token),
        ]);

        $this->audit($actorId, $householdId, 'household.invitation_created');

        return $token;
    }

    public function acceptInvitation(int $userId, string $token): void
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            throw new DomainException('La invitación no es válida o ya venció.');
        }

        $this->db->beginTransaction();

        try {
            $statement = $this->db->prepare(
                'SELECT i.id, i.household_id, i.email, u.email AS user_email
                 FROM invitations i
                 INNER JOIN users u ON u.id = :user_id
                 WHERE i.token_hash = :token_hash
                   AND i.accepted_at IS NULL
                   AND i.revoked_at IS NULL
                   AND i.expires_at > UTC_TIMESTAMP()
                 LIMIT 1
                 FOR UPDATE'
            );
            $statement->execute([
                'user_id' => $userId,
                'token_hash' => hash('sha256', $token),
            ]);
            $invitation = $statement->fetch();

            if (!$invitation || !hash_equals($invitation['email'], $invitation['user_email'])) {
                throw new DomainException('La invitación no es válida para esta cuenta o ya venció.');
            }

            $member = $this->db->prepare(
                'INSERT INTO household_members (household_id, user_id, role)
                 VALUES (:household_id, :user_id, "member")
                 ON DUPLICATE KEY UPDATE status = IF(status = "left", "active", status)'
            );
            $member->execute([
                'household_id' => $invitation['household_id'],
                'user_id' => $userId,
            ]);

            $this->db->prepare('UPDATE invitations SET accepted_at = UTC_TIMESTAMP() WHERE id = :id')
                ->execute(['id' => $invitation['id']]);
            $this->audit($userId, (int) $invitation['household_id'], 'household.invitation_accepted');
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    private function audit(
        int $userId,
        int $householdId,
        string $event,
        ?string $targetType = null,
        ?int $targetId = null
    ): void {
        $statement = $this->db->prepare(
            'INSERT INTO audit_log (
                actor_user_id, household_id, event_type, target_type, target_id
             ) VALUES (
                :user_id, :household_id, :event_type, :target_type, :target_id
             )'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':household_id', $householdId, PDO::PARAM_INT);
        $statement->bindValue(':event_type', $event, PDO::PARAM_STR);
        $statement->bindValue(
            ':target_type',
            $targetType,
            $targetType === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $statement->bindValue(
            ':target_id',
            $targetId,
            $targetId === null ? PDO::PARAM_NULL : PDO::PARAM_INT
        );
        $statement->execute();
    }

    private function memberRole(int $householdId, int $userId, bool $lock = false): ?string
    {
        $sql = 'SELECT role
                FROM household_members
                WHERE household_id = :household_id
                  AND user_id = :user_id
                  AND status = "active"
                LIMIT 1';
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->db->prepare($sql);
        $statement->execute(['household_id' => $householdId, 'user_id' => $userId]);
        $role = $statement->fetchColumn();

        return $role === false ? null : (string) $role;
    }

    private function lockMember(int $householdId, int $memberId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, user_id, role, status
             FROM household_members
             WHERE household_id = :household_id AND id = :id
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['household_id' => $householdId, 'id' => $memberId]);
        $member = $statement->fetch();

        return $member ?: null;
    }
}
