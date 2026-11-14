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
 * `marketplace_source_type_draft` от компании платформы: справочник кодов общий, утечки данных
 * клиента нет, поэтому подключение компаний к ИИ не учитывается — нужен только общий выключатель.
 *
 * Номер задачи пишется в `ai_draft` до постановки (ключ повтора — сам код), поэтому ответ
 * найдёт свою строку. Сервис выключен или недоступен — запрос не ставится и повторится следующим
 * прогоном; экран работает и без черновика. Вызывать вне транзакции.
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
        if ([] === $pending || !$this->ai->isServiceEnabled()) {
            return 0;
        }

        $kinds = array_map(static fn (OperationKind $k): array => ['code' => $k->value, 'level' => $k->level()->value, 'title' => $k->label()], OperationKind::cases());
        $examples = [];
        foreach ($this->store->decidedSourceTypes() as $sourceType => $decided) {
            $examples[] = ['source_type' => $sourceType, 'name' => $decided['name'], 'kind' => $decided['kind']->value];
        }

        $requested = 0;
        foreach ($pending as $row) {
            $taskId = $this->ai->taskIdFor(AiTaskFacadeInterface::PLATFORM_COMPANY_ID, self::TASK_TYPE, $row['source_type']);
            $now = $this->clock->now()->format(\DATE_ATOM);
            $this->store->saveDraft($row['source_type'], ['task_id' => $taskId, 'status' => 'requested', 'requested_at' => $now]);
            try {
                $accepted = $this->ai->requestTask(AiTaskFacadeInterface::PLATFORM_COMPANY_ID, self::TASK_TYPE, [
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
                // сервис выключили между проверкой и постановкой — повторим следующим прогоном
                $this->store->saveDraft($row['source_type'], null);
                continue;
            }
            ++$requested;
        }

        return $requested;
    }
}
