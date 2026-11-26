<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DomainException;

final class FinancialSpaceService
{
    public function __construct(private readonly HouseholdService $households)
    {
    }

    public function listForUser(int $userId): array
    {
        $spaces = [[
            'key' => 'personal',
            'scope' => 'personal',
            'owner_user_id' => $userId,
            'household_id' => null,
            'name' => 'Personal',
            'role' => 'owner',
        ]];

        foreach ($this->households->forUser($userId) as $household) {
            $spaces[] = [
                'key' => 'household:' . (int) $household['id'],
                'scope' => 'household',
                'owner_user_id' => null,
                'household_id' => (int) $household['id'],
                'name' => $household['name'],
                'role' => $household['role'],
            ];
        }

        return $spaces;
    }

    public function resolve(int $userId, mixed $spaceKey, bool $requireManager = false): array
    {
        if ($spaceKey === 'personal') {
            return [
                'key' => 'personal',
                'scope' => 'personal',
                'owner_user_id' => $userId,
                'household_id' => null,
                'name' => 'Personal',
                'role' => 'owner',
            ];
        }

        if (!is_string($spaceKey) || !preg_match('/\Ahousehold:([1-9]\d*)\z/', $spaceKey, $matches)) {
            throw new DomainException('Selecciona un espacio financiero válido.');
        }

        $householdId = filter_var($matches[1], FILTER_VALIDATE_INT);
        if (!$householdId || $householdId < 1) {
            throw new DomainException('Selecciona un espacio financiero válido.');
        }

        foreach ($this->households->forUser($userId) as $household) {
            if ((int) $household['id'] !== (int) $householdId) {
                continue;
            }
            if ($requireManager && !in_array($household['role'], ['owner', 'admin'], true)) {
                throw new DomainException('Solo el propietario o un administrador puede cambiar la configuración de este hogar.');
            }

            return [
                'key' => 'household:' . (int) $householdId,
                'scope' => 'household',
                'owner_user_id' => null,
                'household_id' => (int) $householdId,
                'name' => $household['name'],
                'role' => $household['role'],
            ];
        }

        throw new DomainException('No tienes acceso a ese espacio financiero.');
    }
}
