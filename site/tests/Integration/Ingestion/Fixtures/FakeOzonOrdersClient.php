<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Fixtures;

use App\Ingestion\Enum\IngestOrderScheme;
use App\Ingestion\Infrastructure\Api\Ozon\OzonOrdersClientInterface;
use App\Ingestion\Infrastructure\Api\Ozon\OzonRawPage;

final class FakeOzonOrdersClient implements OzonOrdersClientInterface
{
    /**
     * @var list<array{scheme: string, since: string, to: string, limit: int, offset: int}>
     */
    public array $calls = [];

    /**
     * @var list<OzonRawPage|\Throwable>
     */
    private array $queued = [];

    public function queue(OzonRawPage|\Throwable ...$pages): void
    {
        $this->queued = array_values($pages);
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
