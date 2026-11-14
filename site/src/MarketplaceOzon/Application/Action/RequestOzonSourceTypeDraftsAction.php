<?php

declare(strict_types=1);

namespace App\MarketplaceOzon\Application\Action;

use App\MarketplaceOperations\Enum\OperationKind;
use App\MarketplaceOzon\Infrastructure\Query\OzonTranslationStore;
use App\ServiceGateway\Facade\AiTaskFacadeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Черновик ИИ для каждого нового кода без решения (`DATA.md` §9.4): ставит задачу
 * `marketplace_source_type_draft` от компании, у которой код встретился впервые, а если у неё ИИ
 * выключен — от другой компании с этим кодом в карантине, у которой ИИ включён (её бюджет и учёт).
 *
 * Номер задачи пишется в `ai_draft` до постановки (ключ повтора — сам код), поэтому ответ
 * найдёт свою строку. Сервис недоступен — запрос снимается и повторится следующим прогоном;
 * ИИ выключен у всех таких компаний — черновика не будет, экран работает без него. Вызывать вне транзакции.
 */
final readonly class RequestOzonSourceTypeDraftsAction
{
    public const TASK_TYPE = 'marketplace_source_type_draft';

    public function __construct(
        private OzonTranslationStore $store,
        private AiTaskFacadeInterface $ai,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return int поставлено задач
     */
    public function __invoke(): int
    {
        $pending = $this->store->pendingWithoutDraft();
        if ([] === $pending) {
            return 0;
        }

        $kinds = array_map(static fn (OperationKind $k): array => ['code' => $k->value, 'level' => $k->level()->value, 'title' => $k->label()], OperationKind::cases());
        $examples = [];
        foreach ($this->store->decidedSourceTypes() as $sourceType => $decided) {
            $examples[] = ['source_type' => $sourceType, 'name' => $decided['name'], 'kind' => $decided['kind']->value];
        }

        $requested = 0;
        foreach ($pending as $row) {
            $enabled = array_flip($this->ai->enabledCompanyIds($row['company_ids']));
            $companyId = $row['company_ids'][0];
            foreach ($row['company_ids'] as $candidate) {
                if (isset($enabled[strtolower($candidate)])) {
                    $companyId = $candidate;
                    break;
                }
            }
            $taskId = $this->ai->taskIdFor($companyId, self::TASK_TYPE, $row['source_type']);
            $now = $this->clock->now()->format(\DATE_ATOM);
            $this->store->saveDraft($row['source_type'], ['task_id' => $taskId, 'status' => 'requested', 'requested_at' => $now]);
            try {
                $accepted = $this->ai->requestTask($companyId, self::TASK_TYPE, [
                    'channel' => 'ozon',
                    'source_type' => $row['source_type'],
                    'name' => $row['name'],
                    'sign' => $row['debit'] ? 'debit' : 'credit',
                    'kinds' => $kinds,
                    'examples' => \array_slice($examples, 0, 200),
                ], $row['source_type']);
            } catch (\Throwable $e) {
                $this->store->saveDraft($row['source_type'], null);
                $this->logger->warning('Ozon source type draft request failed, will retry.', ['sourceType' => $row['source_type'], 'error' => $e->getMessage()]);
                continue;
            }
            if (null === $accepted) {
                $this->store->saveDraft($row['source_type'], ['status' => 'unavailable', 'requested_at' => $now]);
                continue;
            }
            ++$requested;
        }

        return $requested;
    }
}
