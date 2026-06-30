<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Api\Ozon;

interface OzonAccrualClientInterface
{
    /**
     * @param list<string> $postingNumbers Ozon posting numbers, 1..200 per request.
     */
    public function fetchPostings(
        string $companyId,
        string $connectionRef,
        array $postingNumbers,
    ): OzonRawPage;

    public function fetchByDay(
        string $companyId,
        string $connectionRef,
        \DateTimeImmutable $date,
        ?string $lastId = null,
    ): OzonRawPage;

    public function fetchTypes(string $companyId, string $connectionRef): OzonRawPage;
}
