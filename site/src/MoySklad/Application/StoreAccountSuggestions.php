<?php

declare(strict_types=1);

namespace App\MoySklad\Application;

use App\Balance\Application\DTO\BalancePostingAccount;
use App\MoySklad\Application\DTO\ResolvedStoreSuggestion;
use App\MoySklad\Domain\StoreAccountRulePolicy;
use App\MoySklad\Domain\StoreAccountSuggestion;

/**
 * Предложения для складов без назначения: правило, иначе ответ ИИ на текущее название склада;
 * код счёта → действующий счёт актива компании. Нет такого счёта (шаблон без него, счёт в архиве) — предложения нет.
 */
final readonly class StoreAccountSuggestions
{
    public function __construct(private StoreAccountRulePolicy $rules)
    {
    }

    /**
     * @param list<array{externalId: string, name: string, pathName?: string, accountId: ?string, excluded: bool}> $stores
     * @param list<BalancePostingAccount> $accounts
     * @param array<string, array{fingerprint: string, accountCode: ?string, excluded: bool, confident: bool, reason: string}> $answers ответы ИИ по external id
     *
     * @return array<string, ResolvedStoreSuggestion> по external id склада
     */
    public function forStores(array $stores, array $accounts, array $answers = []): array
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
            [$source, $confident] = ['rule', true];
            $answer = $answers[$store['externalId']] ?? null;
            if (null === $suggestion && null !== $answer && $answer['fingerprint'] === self::fingerprint($store['name'], $store['pathName'] ?? '')
                && (null !== $answer['accountCode'] || $answer['excluded'])) {
                $suggestion = new StoreAccountSuggestion($answer['accountCode'], $answer['excluded'], $answer['reason']);
                [$source, $confident] = ['ai', $answer['confident']];
            }
            if (null === $suggestion) {
                continue;
            }
            if ($suggestion->excluded) {
                $result[$store['externalId']] = new ResolvedStoreSuggestion('excluded', 'Не учитывать', $suggestion->reason, $suggestion->warning, $source, $confident);
            } elseif (isset($byCode[(string) $suggestion->accountCode])) {
                $account = $byCode[(string) $suggestion->accountCode];
                $result[$store['externalId']] = new ResolvedStoreSuggestion($account->id, $account->name, $suggestion->reason, $suggestion->warning, $source, $confident);
            }
        }

        return $result;
    }

    /** Отпечаток склада для ИИ-ответа: переименование или перенос в другую папку — ответ устарел. */
    public static function fingerprint(string $name, string $pathName): string
    {
        return hash('sha256', $name."\n".$pathName);
    }
}
