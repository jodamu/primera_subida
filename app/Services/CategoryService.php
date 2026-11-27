<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DomainException;
use PDO;

final class CategoryService
{
    public function __construct(
        private readonly PDO $db,
        private readonly FinancialSpaceService $spaces,
    ) {
    }

    /** Categorías propias del espacio (activas y archivadas). */
    public function listForSpace(int $userId, mixed $spaceKey): array
    {
        $space = $this->spaces->resolve($userId, $spaceKey);
        $statement = $this->db->prepare(
            'SELECT id, name, kind, is_active
             FROM categories
             WHERE ' . $this->spaceCondition($space) . '
             ORDER BY kind, is_active DESC, name'
        );
        $statement->execute($this->spaceParams($space));

        return [
            'space' => $space,
            'can_manage' => $this->canManage($space),
            'custom' => $statement->fetchAll(),
            'global' => $this->db->query(
                'SELECT name, kind FROM categories
                 WHERE scope IS NULL AND is_active = 1
                 ORDER BY kind, sort_order, name'
            )->fetchAll(),
        ];
    }

    public function create(int $userId, array $input): void
    {
        $space = $this->manageableSpace($userId, $input['space'] ?? null);
        $kind = $this->kind($input['kind'] ?? null);
        $name = $this->name($input['name'] ?? null);
        $this->assertNameAvailable($space, $kind, $name, null);

        $statement = $this->db->prepare(
            'INSERT INTO categories (
                code, name, kind, scope, owner_user_id, household_id, created_by, sort_order
             ) VALUES (
                :code, :name, :kind, :scope, :owner_user_id, :household_id, :created_by, 100
             )'
        );
        $statement->execute([
            'code' => 'c' . bin2hex(random_bytes(12)),
            'name' => $name,
            'kind' => $kind,
            'scope' => $space['scope'],
            'owner_user_id' => $space['owner_user_id'],
            'household_id' => $space['household_id'],
            'created_by' => $userId,
        ]);
    }

    public function rename(int $userId, array $input): void
    {
        $space = $this->manageableSpace($userId, $input['space'] ?? null);
        $category = $this->ownCategory($space, $input['category_id'] ?? null);
        $name = $this->name($input['name'] ?? null);
        $this->assertNameAvailable($space, $category['kind'], $name, (int) $category['id']);

        $statement = $this->db->prepare('UPDATE categories SET name = :name WHERE id = :id');
        $statement->execute(['name' => $name, 'id' => (int) $category['id']]);
    }

    public function setActive(int $userId, array $input, bool $active): void
    {
        $space = $this->manageableSpace($userId, $input['space'] ?? null);
        $category = $this->ownCategory($space, $input['category_id'] ?? null);
        if ($active) {
            $this->assertNameAvailable($space, $category['kind'], (string) $category['name'], (int) $category['id']);
        }

        $statement = $this->db->prepare('UPDATE categories SET is_active = :active WHERE id = :id');
        $statement->execute(['active' => $active ? 1 : 0, 'id' => (int) $category['id']]);
    }

    private function manageableSpace(int $userId, mixed $spaceKey): array
    {
        $space = $this->spaces->resolve($userId, $spaceKey);
        if (!$this->canManage($space)) {
            throw new DomainException('Solo el propietario o un administrador puede gestionar las categorías del hogar.');
        }

        return $space;
    }

    private function canManage(array $space): bool
    {
        return $space['scope'] === 'personal' || in_array($space['role'], ['owner', 'admin'], true);
    }

    private function ownCategory(array $space, mixed $categoryId): array
    {
        $id = filter_var($categoryId, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            throw new DomainException('Selecciona una categoría válida.');
        }
        $statement = $this->db->prepare(
            'SELECT id, name, kind FROM categories
             WHERE id = :id AND ' . $this->spaceCondition($space) . '
             LIMIT 1'
        );
        $statement->execute(['id' => (int) $id] + $this->spaceParams($space));
        $category = $statement->fetch();
        if (!$category) {
            throw new DomainException('Solo puedes modificar las categorías propias de este espacio.');
        }

        return $category;
    }

    private function assertNameAvailable(array $space, string $kind, string $name, ?int $exceptId): void
    {
        $statement = $this->db->prepare(
            'SELECT 1 FROM categories
             WHERE kind = :kind AND name = :name AND is_active = 1 AND id <> :except_id
               AND (scope IS NULL OR (' . $this->spaceCondition($space) . '))
             LIMIT 1'
        );
        $statement->execute([
            'kind' => $kind,
            'name' => $name,
            'except_id' => $exceptId ?? 0,
        ] + $this->spaceParams($space));
        if ($statement->fetchColumn()) {
            throw new DomainException('Ya existe una categoría con ese nombre en este espacio.');
        }
    }

    private function spaceCondition(array $space): string
    {
        return $space['scope'] === 'personal'
            ? '(scope = "personal" AND owner_user_id = :owner_user_id)'
            : '(scope = "household" AND household_id = :household_id)';
    }

    private function spaceParams(array $space): array
    {
        return $space['scope'] === 'personal'
            ? ['owner_user_id' => (int) $space['owner_user_id']]
            : ['household_id' => (int) $space['household_id']];
    }

    private function kind(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['income', 'expense'], true)) {
            throw new DomainException('Selecciona si la categoría es de ingreso o de gasto.');
        }

        return $value;
    }

    private function name(mixed $value): string
    {
        if (!is_string($value)) {
            throw new DomainException('El nombre de la categoría no es válido.');
        }
        $name = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($name === '' || mb_strlen($name) > 80) {
            throw new DomainException('El nombre debe tener entre 1 y 80 caracteres.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new DomainException('El nombre contiene caracteres no permitidos.');
        }

        return $name;
    }
}
