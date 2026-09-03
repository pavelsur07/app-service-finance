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

    /**
     * Одно отправление по номеру — для перепроса статуса.
     *
     * Возвращает `null`, если Ozon отправления не знает: заказ мог быть удалён
     * или номер устарел, и это не повод ронять весь цикл перепроса.
     *
     * @return array<string, mixed>|null
     */
    public function fetchPosting(
        string $companyId,
        string $connectionRef,
        IngestOrderScheme $scheme,
        string $postingNumber,
    ): ?array;
}
