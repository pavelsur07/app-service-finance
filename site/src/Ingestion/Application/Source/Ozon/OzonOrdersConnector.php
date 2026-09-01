<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\DTO\PullRequest;
use App\Ingestion\Application\DTO\PullResult;
use App\Ingestion\Application\DTO\PushRequest;
use App\Ingestion\Application\DTO\PushResult;
use App\Ingestion\Application\DTO\ShopDescriptor;
use App\Ingestion\Domain\Contract\SourceConnectorInterface;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Enum\Capability;
use App\Ingestion\Enum\IngestOrderScheme;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Exception\UnsupportedCapabilityException;
use App\Ingestion\Infrastructure\Api\Ozon\OzonOrdersClientInterface;
use Psr\Clock\ClockInterface;

/**
 * Почасовой обход заказов Ozon по обеим схемам.
 *
 * Пагинация съедается внутри одного pull: наружу торчит только часовой курсор,
 * а не смещение внутри окна. Ограничение числа страниц за вызов — не
 * перестраховка: `IngestRateLimitGuard` держит блокировку с конечным TTL и
 * внутри цикла её не продлевает, поэтому длинный обход одной пачкой рискует
 * потерять lock молча.
 */
final readonly class OzonOrdersConnector implements SourceConnectorInterface
{
    public const PAGE_LIMIT = 1000;

    /**
     * Перекрытие окна назад. Отправление, созданное за миг до прошлого
     * запроса, иначе не попало бы ни в одно окно: Ozon фильтрует по времени
     * создания, а оно проставляется на его стороне.
     */
    private const WINDOW_OVERLAP_MINUTES = 15;

    /** Сколько страниц берём за один pull, чтобы не пересидеть TTL блокировки. */
    private const MAX_PAGES_PER_PULL = 20;

    public function __construct(
        private OzonOrdersClientInterface $client,
        private ClockInterface $clock,
    ) {
    }

    public function source(): IngestSource
    {
        return IngestSource::OZON;
    }

    public function resourceTypes(): array
    {
        return [OzonResourceType::ORDERS_FBO, OzonResourceType::ORDERS_FBS];
    }

    public function capabilities(): array
    {
        return [Capability::CAN_PULL];
    }

    public function discoverShops(string $companyId, string $connectionRef): array
    {
        return [new ShopDescriptor(
            externalId: $connectionRef,
            name: 'Ozon Seller',
            currency: 'RUB',
            metadata: ['connectionRef' => $connectionRef],
        )];
    }

    public function pull(PullRequest $request): PullResult
    {
        $scheme = OzonResourceType::ORDERS_FBS === $request->resourceType
            ? IngestOrderScheme::FBS
            : IngestOrderScheme::FBO;

        $now = $this->clock->now();
        $cursorSince = $this->decodeCursorSince($request->cursorValue);
        $since = $this->resolveSince($request, $now, $cursorSince);
        $to = $request->windowTo ?? $now;

        $rows = [];
        $offset = 0;
        $pages = 0;
        $hasMorePages = false;

        while (true) {
            $page = $this->client->fetchPostings(
                $request->companyId,
                $request->connectionRef,
                $scheme,
                $since,
                $to,
                self::PAGE_LIMIT,
                $offset,
            );

            array_push($rows, ...$page->rows);
            $offset += self::PAGE_LIMIT;
            ++$pages;

            if (!$page->hasMore) {
                break;
            }

            if ($pages >= self::MAX_PAGES_PER_PULL) {
                $hasMorePages = true;
                break;
            }
        }

        $batch = new RawBatch(
            companyId: $request->companyId,
            connectionRef: $request->connectionRef,
            shopRef: $request->shopRef,
            source: IngestSource::OZON,
            resourceType: $request->resourceType,
            externalId: $this->windowKey($scheme, $since, $to),
            syncJobId: $request->syncJobId,
            fetchedAt: $now,
            rows: [] === $rows ? [$this->emptyMarker($request->resourceType, $since, $to)] : $rows,
        );

        // Курсор не едет назад. IngestCursor::advance() монотонность не
        // проверяет, а откат заставил бы перекачивать одно и то же вечно.
        //
        // Сравнение именно с ИСХОДНЫМ значением курсора, а не с $since:
        // $since уже сдвинут назад на перекрытие окна, и вернуть его значило
        // бы каждый час откатывать позицию на четверть часа.
        $nextSince = max($to, $cursorSince ?? $to);

        return new PullResult(
            rawBatch: $batch,
            nextCursorValue: json_encode(['since' => $nextSince->format(\DATE_ATOM)], \JSON_THROW_ON_ERROR),
            hasMore: $hasMorePages,
            continuationDelaySeconds: $hasMorePages ? 1 : null,
        );
    }

    public function push(PushRequest $request): PushResult
    {
        throw new UnsupportedCapabilityException('Ozon orders connector does not support push.');
    }

    private function resolveSince(
        PullRequest $request,
        \DateTimeImmutable $now,
        ?\DateTimeImmutable $cursorSince,
    ): \DateTimeImmutable {
        if (null !== $request->windowFrom) {
            return $request->windowFrom;
        }

        $since = $cursorSince ?? $now->modify('-1 hour');

        return $since->modify(sprintf('-%d minutes', self::WINDOW_OVERLAP_MINUTES));
    }

    private function decodeCursorSince(?string $cursorValue): ?\DateTimeImmutable
    {
        if (null === $cursorValue || '' === trim($cursorValue)) {
            return null;
        }

        try {
            $payload = json_decode($cursorValue, true, 512, \JSON_THROW_ON_ERROR);
            if (!is_array($payload) || !is_string($payload['since'] ?? null)) {
                return null;
            }

            return new \DateTimeImmutable($payload['since']);
        } catch (\Throwable) {
            // Нечитаемый курсор не должен останавливать загрузку: считаем, что
            // позиции нет, и берём окно по умолчанию.
            return null;
        }
    }

    /**
     * Детерминированный ключ окна вместо порядкового номера: неизменившееся
     * окно даёт тот же hash и не создаёт нового объекта в хранилище.
     */
    private function windowKey(IngestOrderScheme $scheme, \DateTimeImmutable $since, \DateTimeImmutable $to): string
    {
        return sprintf(
            '%s:window-%s-%s',
            $scheme->value,
            $since->format('Y-m-d\TH'),
            $to->format('Y-m-d\TH'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyMarker(string $resourceType, \DateTimeImmutable $since, \DateTimeImmutable $to): array
    {
        return [
            '_ingestion_empty' => true,
            '_ingestion_resource' => $resourceType,
            '_ingestion_metadata' => [
                'since' => $since->format(\DATE_ATOM),
                'to' => $to->format(\DATE_ATOM),
            ],
        ];
    }
}
