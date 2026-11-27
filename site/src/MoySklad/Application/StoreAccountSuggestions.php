<?php

declare(strict_types=1);

namespace App\MoySklad\Application;

use App\Balance\Application\DTO\BalancePostingAccount;
use App\MoySklad\Application\DTO\ResolvedStoreSuggestion;
use App\MoySklad\Domain\StoreAccountRulePolicy;

/**
 * Предложения для складов без назначения: код счёта из правила → действующий счёт актива компании.
 * Нет такого счёта (шаблон без него, счёт в архиве) — предложения нет.
 */
final readonly class StoreAccountSuggestions
{
    public function __construct(private StoreAccountRulePolicy $rules)
    {
    }

    /**
     * @param list<array{externalId: string, name: string, accountId: ?string, excluded: bool}> $stores
     * @param list<BalancePostingAccount> $accounts
     *
     * @return array<string, ResolvedStoreSuggestion> по external id склада
     */
    public function forStores(array $stores, array $accounts): array
    {
        $byCode = [];
        foreach ($accounts as $account) {
            if ($account->isAsset) {
                $byCode[$account->code] = $account;
            }
        }
        $result = [];
        foreach ($stores as $store) {
            if (null !== $store['accountId'] || $store['excluded']) {
                continue;
            }
            $suggestion = $this->rules->suggest($store['name']);
            if (null === $suggestion) {
                continue;
            }
            if ($suggestion->excluded) {
                $result[$store['externalId']] = new ResolvedStoreSuggestion('excluded', 'Не учитывать', $suggestion->reason, $suggestion->warning, 'rule');
            } elseif (isset($byCode[(string) $suggestion->accountCode])) {
                $account = $byCode[(string) $suggestion->accountCode];
                $result[$store['externalId']] = new ResolvedStoreSuggestion($account->id, $account->name, $suggestion->reason, $suggestion->warning, 'rule');
            }
        }

        return $result;
    }
}
