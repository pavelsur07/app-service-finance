<?php

declare(strict_types=1);

namespace App\MoySklad\Application\Action;

use App\Balance\Facade\BalanceFacade;
use App\MoySklad\Application\StoreAccountSuggestions;
use App\MoySklad\Domain\StoreAccountRulePolicy;
use App\MoySklad\Infrastructure\Query\StoreAccountSuggestionStore;
use App\MoySklad\Infrastructure\Query\StoreBalanceQuery;
use App\ServiceGateway\Facade\AiTaskFacadeInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Webmozart\Assert\Assert;

/**
 * ИИ-предложение счёта для складов без назначения, которые не разобрали правила (plan.md §4).
 * Одна задача — один склад; примеры — уже назначенные склады этого подключения. Ключ повтора — склад, его отпечаток,
 * версия промпта и номер попытки: переименование или новый промпт — новый вопрос; зависший дольше часа запрос ставится
 * снова тем же ключом (сервис вернёт ту же задачу); технический сбой (`failed`) — новой попыткой, не больше
 * MAX_ATTEMPTS. Компания без доступа к ИИ или с исчерпанным лимитом — ничего не ставится, страница живёт на правилах.
 * Вызывать вне транзакции: строка пишется до постановки, иначе быстрый ответ не найдёт её.
 */
final readonly class RequestStoreAccountSuggestionsAction
{
    public const TASK_TYPE = 'moysklad_store_account_suggest';
    public const PROMPT_VERSION = 'moysklad_store_account_suggest/v1';
    private const STALE_REQUEST = '-1 hour';
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private StoreBalanceQuery $query,
        private BalanceFacade $balance,
        private StoreAccountRulePolicy $rules,
        private StoreAccountSuggestionStore $store,
        private AiTaskFacadeInterface $ai,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /** @return int поставлено задач */
    public function __invoke(string $companyId, string $connectionId): int
    {
        Assert::uuid($companyId);
        Assert::uuid($connectionId);
        if (!$this->ai->isEnabledForCompany($companyId)) {
            return 0;
        }
        if ($this->ai->budgetFor($companyId)->exceeded()) {
            $this->logger->warning('MoySklad store account suggestions skipped: monthly AI budget is exhausted.', ['companyId' => $companyId]);

            return 0;
        }
        $accounts = $codes = [];
        foreach ($this->balance->getPostingAccounts($companyId) as $account) {
            if ($account->isAsset && \count($accounts) < 200) { // предел контракта; примеры — только на переданные счета
                $accounts[] = ['code' => $account->code, 'name' => $account->name, 'article_path' => $account->articlePath];
                $codes[$account->id] = $account->code;
            }
        }
        if ([] === $accounts) {
            return 0;
        }
        $stores = $this->query->stores($companyId, $connectionId, null);
        $examples = [];
        foreach ($stores as $s) {
            if ($s['excluded'] || isset($codes[(string) $s['accountId']])) {
                $examples[] = ['name' => $s['name'], 'account_code' => $s['excluded'] ? null : $codes[(string) $s['accountId']], 'excluded' => $s['excluded']];
            }
        }
        $requests = $this->store->requests($companyId, $connectionId);
        $now = $this->clock->now();
        $stale = $now->modify(self::STALE_REQUEST);
        $requested = 0;
        foreach ($stores as $s) {
            if (null !== $s['accountId'] || $s['excluded'] || null !== $this->rules->suggest($s['name'])) {
                continue;
            }
            $fingerprint = StoreAccountSuggestions::fingerprint($s['name'], $s['pathName']);
            $previous = $requests[$s['externalId']] ?? null;
            $attempt = 1;
            if (null !== $previous && $previous['fingerprint'] === $fingerprint && self::PROMPT_VERSION === $previous['promptVersion']) {
                $attempt = match ($previous['status']) {
                    'failed' => $previous['attempts'] < self::MAX_ATTEMPTS ? $previous['attempts'] + 1 : null,
                    'requested' => new \DateTimeImmutable($previous['requestedAt'], new \DateTimeZone('UTC')) <= $stale ? $previous['attempts'] : null,
                    default => null, // ответ получен
                };
                if (null === $attempt) {
                    continue;
                }
            }
            $key = $connectionId.':'.$s['externalId'].':'.$fingerprint.':'.self::PROMPT_VERSION.($attempt > 1 ? ':attempt:'.$attempt : '');
            $taskId = $this->ai->taskIdFor($companyId, self::TASK_TYPE, $key);
            $this->store->markRequested($companyId, $connectionId, $s['externalId'], $fingerprint, self::PROMPT_VERSION, $taskId, $attempt, $now);
            try {
                $accepted = $this->ai->requestTask($companyId, self::TASK_TYPE, [
                    'store' => ['name' => $s['name'], 'path_name' => $s['pathName']],
                    'accounts' => $accounts,
                    'examples' => \array_slice($examples, 0, 100),
                ], $key);
            } catch (\Throwable $e) {
                $this->store->forget($companyId, $taskId);
                $this->logger->warning('MoySklad store account suggestion request failed, will retry on next sync.', ['companyId' => $companyId, 'connectionId' => $connectionId, 'error' => $e->getMessage()]);
                continue;
            }
            if (null === $accepted) {
                $this->store->forget($companyId, $taskId); // доступ компании выключили между проверкой и постановкой

                continue;
            }
            ++$requested;
        }
        if ($requested > 0) {
            $this->logger->info('MoySklad store account suggestions requested.', ['companyId' => $companyId, 'connectionId' => $connectionId, 'tasks' => $requested]);
        }

        return $requested;
    }
}
