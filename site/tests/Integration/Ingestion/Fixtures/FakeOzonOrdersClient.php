<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Fixtures;

use App\Ingestion\Enum\IngestOrderScheme;
use App\Ingestion\Infrastructure\Api\Ozon\OzonOrdersClientInterface;
use App\Ingestion\Infrastructure\Api\Ozon\OzonRawPage;

final class FakeOzonOrdersClient implements OzonOrdersClientInterface
{
    /**
     * Вызовы обоих эндпоинтов: у списка и у одиночного отправления разный
     * набор ключей, поэтому тип общий.
     *
     * @var list<array<string, mixed>>
     */
    public array $calls = [];

    /**
     * @var list<OzonRawPage|\Throwable>
     */
    private array $queued = [];

    /** @var array<string, array<string, mixed>|null> */
    private array $postings = [];

    public function queue(OzonRawPage|\Throwable ...$pages): void
    {
        $this->queued = array_values($pages);
    }

    /**
     * @param array<string, array<string, mixed>|null> $postings номер => отправление
     */
    public function setPostings(array $postings): void
    {
        $this->postings = $postings;
    }

    public function fetchPosting(
        string $companyId,
        string $connectionRef,
        IngestOrderScheme $scheme,
        string $postingNumber,
    ): ?array {
        $this->calls[] = ['endpoint' => 'posting_get', 'postingNumber' => $postingNumber];

        return $this->postings[$postingNumber] ?? null;
    }

    public function fetchPostings(
        string $companyId,
        string $connectionRef,
        IngestOrderScheme $scheme,
        \DateTimeImmutable $since,
        \DateTimeImmutable $to,
        int $limit,
        int $offset,
    ): OzonRawPage {
        $this->calls[] = [
            'scheme' => $scheme->value,
            'since' => $since->format(\DATE_ATOM),
            'to' => $to->format(\DATE_ATOM),
            'limit' => $limit,
            'offset' => $offset,
        ];

        $next = array_shift($this->queued) ?? new OzonRawPage([], false, null, []);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}
