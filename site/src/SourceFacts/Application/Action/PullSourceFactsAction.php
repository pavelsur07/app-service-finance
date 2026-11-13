<?php

declare(strict_types=1);

namespace App\SourceFacts\Application\Action;

use App\ServiceGateway\Application\DTO\SourceFactBatch;
use App\ServiceGateway\Exception\ContractViolationException;
use App\ServiceGateway\Exception\ServiceUnavailableException;
use App\ServiceGateway\Facade\SourceFactsGatewayFacadeInterface;
use App\SourceFacts\Application\DTO\PullSourceFactsResult;
use App\SourceFacts\Infrastructure\Query\SourceFactStore;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Забор фактов из сервисов (pull + ack, `source-fact-envelope` v1). Пачка — ревизия периода кабинета.
 *
 * Пачка применяется в две фазы, каждая идемпотентна:
 * 1. **Загрузка страниц** — HTTP вне транзакции, страница пишется одним оператором; новые версии
 *    записей до активации не действуют.
 * 2. **Активация** — одна транзакция: ревизия становится текущей, только если она новее
 *    (условие в самом операторе, блокировка не нужна), и строка журнала. После commit — ack.
 * Оборванный прогон повторяется целиком: до ack сервис отдаёт пачку снова.
 *
 * Пачка, которую принять нельзя (нет подключения, ответ не по контракту, страница потеряна),
 * уходит в карантин журнала и **не** подтверждается. Сервис недоступен — прогон прерывается,
 * повтор по расписанию. Модуль не знает каналов: ресурс — непрозрачная строка, запись не разбирается.
 */
final readonly class PullSourceFactsAction
{
    public const PAGE_SIZE = 100;
    public const ALL_COMPANIES = '*';

    /** @var list<string> */
    private array $companies;

    public function __construct(
        private SourceFactsGatewayFacadeInterface $gateway,
        private SourceFactStore $store,
        private Connection $connection,
        private LoggerInterface $logger,
        #[Autowire('%env(default::SOURCE_FACTS_COMPANIES)%')]
        ?string $companies = null,
    ) {
        $this->companies = array_values(array_filter(array_map(
            static fn (string $id): string => strtolower(trim($id)),
            explode(',', (string) $companies),
        )));
    }

    /** Список компаний пуст — приём выключен. */
    public function isEnabled(): bool
    {
        return [] !== $this->companies;
    }

    /**
     * @throws ServiceUnavailableException
     * @throws ContractViolationException нарушен контракт списка пачек или ack — прогон остановлен
     */
    public function __invoke(): PullSourceFactsResult
    {
        $count = ['received' => 0, 'applied' => 0, 'superseded' => 0, 'quarantined' => 0, 'skipped' => 0];
        $facts = 0;
        /** @var array<string, int> $newlyQuarantined причина → число пачек, впервые попавших в карантин */
        $newlyQuarantined = [];

        foreach ($this->gateway->sources() as $service => $resources) {
            foreach ($resources as $resourceType) {
                $after = null;
                do {
                    $page = $this->gateway->batches($service, $resourceType, $after, self::PAGE_SIZE);
                    $ack = [];
                    foreach ($page->batches as $batch) {
                        ++$count['received'];
                        [$outcome, $applied, $reason] = $this->receive($batch);
                        ++$count[$outcome];
                        $facts += $applied;
                        if (null !== $reason) {
                            $newlyQuarantined[$reason] = ($newlyQuarantined[$reason] ?? 0) + 1;
                        }
                        if (\in_array($outcome, ['applied', 'superseded'], true)) {
                            $ack[] = $batch->batchId;
                        }
                    }
                    if ([] !== $ack) {
                        $this->gateway->ack($service, $ack);
                    }
                    if (null !== $page->nextCursor && $page->nextCursor === $after) {
                        throw new ContractViolationException('Contract source-fact-envelope/v1 violated at $.next_cursor: must change between pages');
                    }
                    $after = $page->nextCursor;
                } while (null !== $after);
            }
        }

        if ([] !== $newlyQuarantined) {
            // Один error на прогон и только о новых пачках: повторы видит ежедневный гейт.
            $this->logger->error('Source fact batches were quarantined and left unacknowledged.', [
                'count' => array_sum($newlyQuarantined),
                'reasons' => $newlyQuarantined,
            ]);
        }

        return new PullSourceFactsResult(
            $count['received'],
            $count['applied'],
            $count['superseded'],
            $count['quarantined'],
            $count['skipped'],
            $facts,
        );
    }

    /**
     * @return array{'applied'|'superseded'|'quarantined'|'skipped', int, string|null} исход, число действующих
     *                                                                                 записей, причина нового карантина
     */
    private function receive(SourceFactBatch $batch): array
    {
        if (null === $batch->companyId) {
            return $this->quarantine($batch, 'unknown_connection', null);
        }
        if (!\in_array(self::ALL_COMPANIES, $this->companies, true) && !\in_array($batch->companyId, $this->companies, true)) {
            return ['skipped', 0, null];
        }

        $current = $this->store->currentRevision($batch);
        if ($batch->revision > $current) {
            // Фаза 1: загрузка страниц.
            try {
                for ($pageNo = 1; $pageNo <= $batch->pageCount; ++$pageNo) {
                    $page = $this->gateway->page($batch->service, $batch->batchId, $pageNo);
                    if ($page->pageCount !== $batch->pageCount) {
                        throw new ContractViolationException('Contract source-fact-envelope/v1 violated at $.page_count: differs from the batch');
                    }
                    $this->store->stagePage($batch, $page->body, $current);
                }
            } catch (ContractViolationException $e) {
                return $this->quarantine($batch, 'contract_violation', $e->getMessage());
            }
        }

        // Фаза 2: активация.
        return $this->connection->transactional(function () use ($batch): array {
            $facts = $this->store->activate($batch);
            $this->store->journal($batch, null === $facts ? SourceFactStore::STATUS_SUPERSEDED : SourceFactStore::STATUS_APPLIED);

            return null === $facts ? ['superseded', 0, null] : ['applied', $facts, null];
        });
    }

    /**
     * @return array{'quarantined', int, string|null}
     */
    private function quarantine(SourceFactBatch $batch, string $reason, ?string $details): array
    {
        $new = $this->store->journal($batch, SourceFactStore::STATUS_QUARANTINED, $reason, $details);

        return ['quarantined', 0, $new ? $reason : null];
    }
}
