<?php

declare(strict_types=1);

namespace App\MoySklad\Application\Action;

use App\Balance\Facade\BalanceFacade;
use App\MoySklad\Application\StoreAccountSuggestions;
use App\MoySklad\Exception\StoreBalanceException;
use App\MoySklad\Infrastructure\Query\StoreBalanceQuery;
use Webmozart\Assert\Assert;

/**
 * «Принять все уверенные»: предложения пересчитываются на сервере и применяются только к складам без назначения.
 * Каждое назначение — своя транзакция SaveStoreBalanceLinkAction (склады независимы): отказ по одному складу
 * (счёт занят другим подключением) не отменяет остальные и попадает в failed. Нет права проводить в Балансе —
 * исключение на первом же складе, ничего не записано. Повтор безопасен: назначенные склады пропускаются.
 */
final readonly class AcceptStoreAccountSuggestionsAction
{
    public function __construct(
        private StoreBalanceQuery $query,
        private BalanceFacade $balance,
        private StoreAccountSuggestions $suggestions,
        private SaveStoreBalanceLinkAction $link,
    ) {
    }

    /** @return array{accepted: int, failed: int} */
    public function __invoke(string $companyId, string $connectionId, string $actorUserId): array
    {
        Assert::uuid($companyId);
        Assert::uuid($connectionId);
        $suggestions = $this->suggestions->forStores($this->query->stores($companyId, $connectionId, null), $this->balance->getPostingAccounts($companyId));
        $accepted = $failed = 0;
        foreach ($suggestions as $storeExternalId => $suggestion) {
            $excluded = 'excluded' === $suggestion->target;
            try {
                $accepted += (int) ($this->link)($companyId, $connectionId, (string) $storeExternalId, $excluded ? null : $suggestion->target, $excluded, $actorUserId, onlyIfUnassigned: true);
            } catch (StoreBalanceException $error) {
                if ('actor_forbidden' === $error->reason) {
                    throw $error;
                }
                ++$failed;
            }
        }

        return ['accepted' => $accepted, 'failed' => $failed];
    }
}
