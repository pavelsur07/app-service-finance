<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Api\Ozon;

interface OzonAccrualClientInterface
{
    public function fetchPostings(
        string $companyId,
        string $connectionRef,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $page,
        int $pageSize,
    ): OzonRawPage;

    public function fetchByDay(
        string $companyId,
        string $connectionRef,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): OzonRawPage;

    public function fetchTypes(string $companyId, string $connectionRef): OzonRawPage;
}
