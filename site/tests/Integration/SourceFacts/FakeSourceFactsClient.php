<?php

declare(strict_types=1);

namespace App\Tests\Integration\SourceFacts;

use App\ServiceGateway\Application\DTO\SourceFactBatch;
use App\ServiceGateway\Application\DTO\SourceFactBatchesPage;
use App\ServiceGateway\Application\DTO\SourceFactPage;
use App\ServiceGateway\Exception\ContractViolationException;
use App\ServiceGateway\Exception\ServiceUnavailableException;
use App\ServiceGateway\Infrastructure\Client\SourceFactsClientInterface;

/**
 * Сервис в памяти: очередь неподтверждённых пачек, как в ozon-fetcher. Выдаёт по одной пачке
 * на страницу списка — так курсор проверяется каждым тестом.
 */
final class FakeSourceFactsClient implements SourceFactsClientInterface
{
    public const RESOURCE = 'ozon.finance.accrual-by-day';

    /** @var array<string, array{batch: SourceFactBatch, pages: list<list<array{string, array<string, mixed>}>>}> */
    private array $queue = [];
    /** @var list<string> */
    private array $order = [];
    /** @var list<string> */
    public array $acked = [];
    /** @var array<string, \Throwable> */
    public array $failPage = [];
    public int $pageRequests = 0;

    /**
     * @param list<list<array{string, array<string, mixed>}>> $pages страницы записей [source_key, payload]
     */
    public function enqueue(string $connectionRef, string $period, int $revision, array $pages): string
    {
        $batchId = sprintf('%s:%s:%s:r%d', $connectionRef, self::RESOURCE, $period, $revision);
        $this->queue[$batchId] = [
            'batch' => new SourceFactBatch('ozon-fetcher', $batchId, $connectionRef, null, self::RESOURCE, $period, $revision, str_repeat('a', 64), \count($pages)),
            'pages' => $pages,
        ];

        $this->order[] = $batchId;

        return $batchId;
    }

    public function service(): string
    {
        return 'ozon-fetcher';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function fetchBatches(string $resourceType, ?string $after, int $limit): SourceFactBatchesPage
    {
        // Курсор — позиция в порядке постановки: подтверждённые пачки её не сдвигают.
        $pending = array_values(array_filter(
            \array_slice($this->order, null === $after ? 0 : (int) $after, null, true),
            fn (string $id): bool => isset($this->queue[$id]),
        ));
        if ([] === $pending) {
            return new SourceFactBatchesPage([], null);
        }
        $position = (int) array_search($pending[0], $this->order, true);

        return new SourceFactBatchesPage([$this->queue[$pending[0]]['batch']], isset($pending[1]) ? (string) ($position + 1) : null);
    }

    public function fetchPage(string $batchId, int $page): SourceFactPage
    {
        ++$this->pageRequests;
        if (isset($this->failPage[$batchId])) {
            throw $this->failPage[$batchId];
        }
        $entry = $this->queue[$batchId] ?? throw new ContractViolationException('HTTP 404');
        $records = array_map(static fn (array $r): array => [
            'source_key' => $r[0],
            'payload_hash' => hash('sha256', json_encode($r[1], \JSON_THROW_ON_ERROR)),
            'payload' => (object) $r[1],
        ], $entry['pages'][$page - 1]);

        return new SourceFactPage($batchId, $page, \count($entry['pages']), \count($records), json_encode(['records' => $records], \JSON_THROW_ON_ERROR));
    }

    public function ack(array $batchIds): int
    {
        if (isset($this->failPage['ack'])) {
            throw new ServiceUnavailableException('ack failed');
        }
        foreach ($batchIds as $id) {
            unset($this->queue[$id]);
            $this->acked[] = $id;
        }

        return \count($batchIds);
    }
}
