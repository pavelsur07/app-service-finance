<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Api\Ozon;

use App\Ingestion\Enum\IngestOrderScheme;

interface OzonOrdersClientInterface
{
    /**
     * Одна страница отправлений за окно.
     */
    public function fetchPostings(
        string $companyId,
        string $connectionRef,
        IngestOrderScheme $scheme,
        \DateTimeImmutable $since,
        \DateTimeImmutable $to,
        int $limit,
        int $offset,
    ): OzonRawPage;
}
