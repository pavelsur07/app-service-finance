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
 * Одна задача — один склад; примеры — уже назначенные склады этого подключения. Ключ повтора — склад, версия промпта
 * и номер запроса склада (растёт с каждым новым payload, не сбрасывается — переименование туда и обратно не попадёт
 * в старую задачу). Переименование или новый промпт — новый вопрос; зависший дольше часа запрос с тем же payload — тот
 * же ключ (сервис вернёт ту же задачу), с другим — новый; технический сбой (`failed`) — новый запрос. Отправок на одно
 * название и промпт — не больше MAX_ATTEMPTS. Компания без доступа к ИИ или с исчерпанным лимитом — ничего не ставится, страница живёт на правилах.
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
            $payload = ['store' => ['name' => $s['name'], 'path_name' => $s['pathName']], 'accounts' => $accounts, 'examples' => \array_slice($examples, 0, 100)];
            $payloadHash = hash('sha256', json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
            $previous = $requests[$s['externalId']] ?? null;
            [$attempt, $seq] = [1, ($previous['seq'] ?? 0) + 1];
            if (null !== $previous && $previous['fingerprint'] === $fingerprint && self::PROMPT_VERSION === $previous['promptVersion']) {
                $retry = match ($previous['status']) {
                    'failed' => true,
                    'requested' => new \DateTimeImmutable($previous['requestedAt'], new \DateTimeZone('UTC')) <= $stale,
                    default => false, // ответ получен
                };
                // Лимит — на все отправки названия: отказ сервиса при отправке (422) до ответа не доходит.
                if (!$retry || $previous['attempts'] >= self::MAX_ATTEMPTS) {
                    continue;
                }
                $attempt = $previous['attempts'] + 1;
                // Тот же payload зависшего запроса — тот же ключ (сервис вернёт ту же задачу); иначе ключ новый,
                // старый с другим payload сервис отверг бы как idempotency_conflict.
                if ('requested' === $previous['status'] && $previous['payloadHash'] === $payloadHash) {
                    $seq = $previous['seq'];
                }
            }
            $key = $connectionId.':'.$s['externalId'].':'.self::PROMPT_VERSION.':'.$seq;
            $taskId = $this->ai->taskIdFor($companyId, self::TASK_TYPE, $key);
            $this->store->markRequested($companyId, $connectionId, $s['externalId'], $fingerprint, self::PROMPT_VERSION, $taskId, $attempt, $seq, $payloadHash, $now);
            try {
                $accepted = $this->ai->requestTask($companyId, self::TASK_TYPE, $payload, $key);
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
