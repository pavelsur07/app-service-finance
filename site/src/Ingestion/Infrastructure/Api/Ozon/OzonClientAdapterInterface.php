<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Api\Ozon;

interface OzonClientAdapterInterface
{
    public function fetchTransactionList(
        string $companyId,
        string $connectionRef,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $page,
        int $pageSize,
    ): OzonRawPage;

    public function fetchRealization(
        string $companyId,
        string $connectionRef,
        int $year,
        int $month,
    ): OzonRawPage;

    /**
     * @return list<OzonShopDescriptor>
     */
    public function listClusters(string $companyId, string $connectionRef): array;
}
