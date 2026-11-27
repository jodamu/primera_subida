<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DomainException;
use PDO;

final class CurrencyService
{
    public const FOREIGN = ['USD', 'EUR'];

    public function __construct(
        private readonly PDO $db,
        private readonly AccountService $accounts,
        private readonly FinancialSpaceService $spaces,
    ) {
    }

    /** Tasas manuales de cada espacio del usuario, con su permiso de edición. */
    public function ratesOverview(int $userId): array
    {
        $overview = [];
        foreach ($this->spaces->listForUser($userId) as $space) {
            $rates = $this->ratesForSpace($space);
            $overview[] = [
                'space' => $space,
                'can_manage' => $space['scope'] === 'personal' || in_array($space['role'], ['owner', 'admin'], true),
                'rates' => $rates,
            ];
        }

        return $overview;
    }

    public function setRate(int $userId, array $input): void
    {
        $space = $this->spaces->resolve($userId, $input['space'] ?? null, true);
        $currency = $input['currency'] ?? null;
        if (!is_string($currency) || !in_array($currency, self::FOREIGN, true)) {
            throw new DomainException('Selecciona USD o EUR.');
        }
        $rate = $input['rate'] ?? null;
        if (!is_string($rate) || !preg_match('/\A\d{1,12}(?:[.,]\d{1,6})?\z/', trim($rate))) {
            throw new DomainException('Ingresa una tasa válida con hasta 6 decimales.');
        }
        $rate = str_replace(',', '.', trim($rate));
        if ((float) $rate <= 0.0) {
            throw new DomainException('La tasa debe ser mayor que cero.');
        }

        $statement = $this->db->prepare(
            'INSERT INTO exchange_rates (scope, owner_user_id, household_id, currency, rate_to_cop, updated_by)
             VALUES (:scope, :owner_user_id, :household_id, :currency, :rate, :updated_by)
             ON DUPLICATE KEY UPDATE rate_to_cop = VALUES(rate_to_cop), updated_by = VALUES(updated_by)'
        );
        $statement->execute([
            'scope' => $space['scope'],
            'owner_user_id' => $space['owner_user_id'],
            'household_id' => $space['household_id'],
            'currency' => $currency,
            'rate' => $rate,
            'updated_by' => $userId,
        ]);
    }

    /** Cuentas en moneda extranjera con su equivalente estimado en COP (solo informativo). */
    public function foreignAccountsSummary(int $userId): array
    {
        $ratesBySpace = [];
        foreach ($this->spaces->listForUser($userId) as $space) {
            foreach ($this->ratesForSpace($space) as $rate) {
                $ratesBySpace[$space['key']][$rate['currency']] = (string) $rate['rate_to_cop'];
            }
        }

        $convert = $this->db->prepare(
            'SELECT CAST(ROUND(CAST(:balance AS DECIMAL(15,2)) * CAST(:rate AS DECIMAL(18,6)), 2) AS CHAR)'
        );
        $result = [];
        foreach ($this->accounts->listForUser($userId, true) as $account) {
            if ($account['currency'] === 'COP') {
                continue;
            }
            $key = $account['scope'] === 'personal' ? 'personal' : 'household:' . (int) $account['household_id'];
            $rate = $ratesBySpace[$key][$account['currency']] ?? null;
            $estimate = null;
            if ($rate !== null) {
                $convert->execute(['balance' => (string) $account['current_balance'], 'rate' => $rate]);
                $estimate = (string) $convert->fetchColumn();
            }
            $result[] = $account + ['rate_to_cop' => $rate, 'estimated_cop' => $estimate, 'space_key' => $key];
        }

        return $result;
    }

    private function ratesForSpace(array $space): array
    {
        if ($space['scope'] === 'personal') {
            $statement = $this->db->prepare(
                'SELECT currency, CAST(rate_to_cop AS CHAR) AS rate_to_cop, updated_at
                 FROM exchange_rates WHERE scope = "personal" AND owner_user_id = :id ORDER BY currency'
            );
            $statement->execute(['id' => (int) $space['owner_user_id']]);
        } else {
            $statement = $this->db->prepare(
                'SELECT currency, CAST(rate_to_cop AS CHAR) AS rate_to_cop, updated_at
                 FROM exchange_rates WHERE scope = "household" AND household_id = :id ORDER BY currency'
            );
            $statement->execute(['id' => (int) $space['household_id']]);
        }

        return $statement->fetchAll();
    }
}
