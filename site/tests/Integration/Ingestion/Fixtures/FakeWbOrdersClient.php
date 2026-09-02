<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Fixtures;

use App\Ingestion\Infrastructure\Api\Wildberries\WbOrdersClientInterface;
use App\Ingestion\Infrastructure\Api\Wildberries\WbOrdersPage;
use App\Ingestion\Infrastructure\Api\Wildberries\WbOrderStatusPage;

final class FakeWbOrdersClient implements WbOrdersClientInterface
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /** @var list<WbOrdersPage> */
    private array $marketplacePages = [];

    /** @var list<WbOrdersPage> */
    private array $statisticsPages = [];

    /** @var array<int, array<string, mixed>> */
    private array $statuses = [];

    /**
     * Сбой, который клиент бросит вместо ответа на статусы. Без этого тесты
     * частичного сбоя проверяли бы не поведение, а фейк.
     */
    private ?\Throwable $statusFailure = null;

    private int $rejectedRows = 0;

    /** @var array<string, mixed>|null */
    private ?array $rejectedEvidence = null;

    public function queueMarketplace(WbOrdersPage ...$pages): void
    {
        $this->marketplacePages = array_values($pages);
    }

    public function queueStatistics(WbOrdersPage ...$pages): void
    {
        $this->statisticsPages = array_values($pages);
    }

    /**
     * @param array<int, array<string, mixed>> $statuses
     */
    public function setStatuses(array $statuses): void
    {
        $this->statuses = $statuses;
    }

    public function fetchMarketplaceOrders(
        string $companyId,
        string $connectionRef,
        \DateTimeImmutable $since,
        int $limit,
        int $next,
    ): WbOrdersPage {
        $this->calls[] = [
            'endpoint' => 'orders',
            'since' => $since->format(\DATE_ATOM),
            'limit' => $limit,
            'next' => $next,
        ];

        return array_shift($this->marketplacePages) ?? new WbOrdersPage([], false, $next);
    }

    public function failStatusesWith(\Throwable $failure): void
    {
        $this->statusFailure = $failure;
    }

    public function fetchMarketplaceStatuses(string $companyId, string $connectionRef, array $orderIds): WbOrderStatusPage
    {
        $this->calls[] = ['endpoint' => 'status', 'ids' => $orderIds];

        if (null !== $this->statusFailure) {
            throw $this->statusFailure;
        }

        $result = [];
        foreach ($orderIds as $id) {
            if (isset($this->statuses[$id])) {
                $result[$id] = $this->statuses[$id];
            }
        }

        return new WbOrderStatusPage(
            statuses: $result,
            rejectedRows: $this->rejectedRows,
            evidence: $this->rejectedEvidence,
        );
    }

    /**
     * @param array<string, mixed>|null $evidence
     */
    public function rejectRows(int $rejected, ?array $evidence = null): void
    {
        $this->rejectedRows = $rejected;
        $this->rejectedEvidence = $evidence;
    }

    public function fetchStatisticsOrders(
        string $companyId,
        string $connectionRef,
        \DateTimeImmutable $since,
    ): WbOrdersPage {
        $this->calls[] = ['endpoint' => 'statistics', 'since' => $since->format(\DATE_ATOM)];

        return array_shift($this->statisticsPages) ?? new WbOrdersPage([], false);
    }
}
