<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Wildberries;

use App\Ingestion\Application\DTO\PullRequest;
use App\Ingestion\Application\DTO\PullResult;
use App\Ingestion\Application\DTO\PushRequest;
use App\Ingestion\Application\DTO\PushResult;
use App\Ingestion\Application\DTO\ShopDescriptor;
use App\Ingestion\Domain\Contract\SourceConnectorInterface;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Enum\Capability;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Exception\UnsupportedCapabilityException;
use App\Ingestion\Infrastructure\Api\Wildberries\WbOrdersClient;
use App\Ingestion\Infrastructure\Api\Wildberries\WbOrdersClientInterface;
use Psr\Clock\ClockInterface;

/**
 * Почасовой обход заказов Wildberries по двум потокам.
 *
 * Потоков именно два, и оба нужны. `/api/v3/orders` знает состав заказа и — в
 * паре с `/api/v3/orders/status` — оба статусных поля, но не показывает
 * отмены, случившиеся позже. `/api/v1/supplier/orders?flag=0` — поток
 * ИЗМЕНЕНИЙ по lastChangeDate: он приносит отмены и правки задним числом, но
 * состава заказа не отдаёт. Сшиваются по `rid = srid` уже на нормализации.
 */
final readonly class WbOrdersConnector implements SourceConnectorInterface
{
    /** Сколько страниц marketplace берём за один pull, чтобы не пересидеть TTL блокировки. */
    public const MAX_PAGES_PER_PULL = 20;

    /**
     * Перекрытие окна назад. Заказ, созданный или изменённый за миг до
     * прошлого запроса, иначе не попал бы ни в одно окно: обе отметки времени
     * проставляются на стороне WB.
     */
    private const WINDOW_OVERLAP_MINUTES = 15;

    /** Глубина посева, если курсора ещё нет. */
    private const DEFAULT_LOOKBACK_HOURS = 1;

    public function __construct(
        private WbOrdersClientInterface $client,
        private ClockInterface $clock,
    ) {
    }

    public function source(): IngestSource
    {
        return IngestSource::WILDBERRIES;
    }

    public function resourceTypes(): array
    {
        return [WbResourceType::ORDERS_MARKETPLACE, WbResourceType::ORDERS_STATISTICS];
    }

    public function capabilities(): array
    {
        return [Capability::CAN_PULL];
    }

    public function discoverShops(string $companyId, string $connectionRef): array
    {
        return [new ShopDescriptor(
            externalId: $connectionRef,
            name: 'Wildberries Seller',
            currency: 'RUB',
            metadata: ['connectionRef' => $connectionRef],
        )];
    }

    public function pull(PullRequest $request): PullResult
    {
        return WbResourceType::ORDERS_STATISTICS === $request->resourceType
            ? $this->pullStatistics($request)
            : $this->pullMarketplace($request);
    }

    public function push(PushRequest $request): PushResult
    {
        throw new UnsupportedCapabilityException('Wildberries orders connector does not support push.');
    }

    private function pullMarketplace(PullRequest $request): PullResult
    {
        $now = $this->clock->now();
        $state = $this->decodeCursor($request->cursorValue);
        $cursorSince = $state['since'];

        // Продолжение несёт замороженное окно и курсор постраничности WB.
        // Без этого остаток окна терялся бы: наружу уходил бы hasMore, а
        // следующий вызов начинал бы новое окно с нулевого `next`.
        $isContinuation = null !== $state['next'];
        if ($isContinuation) {
            $since = $cursorSince ?? $now->modify(sprintf('-%d hours', self::DEFAULT_LOOKBACK_HOURS));
            $next = $state['next'];
            $floor = $state['floor'];
        } else {
            $since = $this->resolveSince($request, $now, $cursorSince);
            $next = 0;
            $floor = $cursorSince;
        }

        $startNext = $next;
        $rows = [];
        $pages = 0;
        $hasMorePages = false;

        while (true) {
            $page = $this->client->fetchMarketplaceOrders(
                $request->companyId,
                $request->connectionRef,
                $since,
                WbOrdersClient::MARKETPLACE_PAGE_LIMIT,
                $next,
            );

            array_push($rows, ...$page->rows);
            $next = $page->nextToken ?? $next;
            ++$pages;

            if (!$page->hasMore) {
                break;
            }

            if ($pages >= self::MAX_PAGES_PER_PULL) {
                $hasMorePages = true;
                break;
            }
        }

        $rows = $this->attachStatuses($request, $rows);

        $batch = new RawBatch(
            companyId: $request->companyId,
            connectionRef: $request->connectionRef,
            shopRef: $request->shopRef,
            source: IngestSource::WILDBERRIES,
            resourceType: $request->resourceType,
            externalId: $this->windowKey($request->resourceType, $since, $now, $startNext),
            syncJobId: $request->syncJobId,
            fetchedAt: $now,
            rows: [] === $rows ? [$this->emptyMarker($request->resourceType, $since, $now)] : $rows,
        );

        if ($hasMorePages) {
            return new PullResult(
                rawBatch: $batch,
                nextCursorValue: json_encode(array_filter([
                    'since' => $since->format(\DATE_ATOM),
                    'next' => $next,
                    'floor' => $floor?->format(\DATE_ATOM),
                ], static fn (mixed $v): bool => null !== $v), \JSON_THROW_ON_ERROR),
                hasMore: true,
                continuationDelaySeconds: 1,
            );
        }

        // Окно дочитано до пустой страницы, значит всё, что WB создал к этому
        // моменту, уже прочитано: курсор переезжает на время запроса. Назад он
        // при этом не едет — пол монотонности переносится через продолжение.
        return new PullResult(
            rawBatch: $batch,
            nextCursorValue: json_encode(
                ['since' => max($now, $floor ?? $now)->format(\DATE_ATOM)],
                \JSON_THROW_ON_ERROR,
            ),
            hasMore: false,
            continuationDelaySeconds: null,
        );
    }

    private function pullStatistics(PullRequest $request): PullResult
    {
        $now = $this->clock->now();
        $state = $this->decodeCursor($request->cursorValue);
        $cursorSince = $state['since'];
        $since = $this->resolveSince($request, $now, $cursorSince);

        $page = $this->client->fetchStatisticsOrders($request->companyId, $request->connectionRef, $since);
        $rows = $page->rows;

        $batch = new RawBatch(
            companyId: $request->companyId,
            connectionRef: $request->connectionRef,
            shopRef: $request->shopRef,
            source: IngestSource::WILDBERRIES,
            resourceType: $request->resourceType,
            externalId: $this->windowKey($request->resourceType, $since, $now, 0),
            syncJobId: $request->syncJobId,
            fetchedAt: $now,
            rows: [] === $rows ? [$this->emptyMarker($request->resourceType, $since, $now)] : $rows,
        );

        // Водяной знак — максимальный lastChangeDate, а НЕ время запроса.
        //
        // flag=0 отдаёт поток изменений, и следующий обход обязан начаться
        // ровно там, где кончился предыдущий. Взять вместо этого «сейчас»
        // значило бы пропустить изменения, которые WB проштампует временем
        // между максимумом и моментом ответа. Граничная строка приедет
        // повторно — это дешевле пропуска и идемпотентно.
        //
        // Если не изменилось ничего, WB тем самым сказал, что после `since`
        // записей нет, и курсор безопасно переезжает на время запроса: иначе
        // окно росло бы бесконечно.
        $watermark = $this->maxLastChangeDate($rows) ?? $now;
        $nextSince = max($watermark, $cursorSince ?? $watermark);

        return new PullResult(
            rawBatch: $batch,
            nextCursorValue: json_encode(['since' => $nextSince->format(\DATE_ATOM)], \JSON_THROW_ON_ERROR),
            hasMore: false,
            continuationDelaySeconds: null,
        );
    }

    /**
     * Статусы подмешиваются в строки ЗДЕСЬ, а не в маппере.
     *
     * У WB состав заказа и его статус живут на разных эндпоинтах, и это
     * особенность формы API, а не доменное решение. Маппер обязан остаться
     * чистым: он получает строку, в которой уже есть всё нужное. Подмешанное
     * лежит под собственным ключом с префиксом `_ingestion_`, чтобы его нельзя
     * было спутать с полем самого WB.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function attachStatuses(PullRequest $request, array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if (is_int($id)) {
                $ids[] = $id;
            }
        }

        if ([] === $ids) {
            return $rows;
        }

        $statuses = [];
        foreach (array_chunk(array_values(array_unique($ids)), WbOrdersClient::STATUS_BATCH_SIZE) as $chunk) {
            $statuses += $this->client->fetchMarketplaceStatuses($request->companyId, $request->connectionRef, $chunk);
        }

        $merged = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if (is_int($id) && isset($statuses[$id])) {
                $row['_ingestion_status'] = $statuses[$id];
            }

            $merged[] = $row;
        }

        return $merged;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function maxLastChangeDate(array $rows): ?\DateTimeImmutable
    {
        $max = null;
        foreach ($rows as $row) {
            $parsed = WbOrderDateParser::parseStatisticsInstant($row['lastChangeDate'] ?? null);
            if (null !== $parsed && (null === $max || $parsed > $max)) {
                $max = $parsed;
            }
        }

        return $max;
    }

    private function resolveSince(
        PullRequest $request,
        \DateTimeImmutable $now,
        ?\DateTimeImmutable $cursorSince,
    ): \DateTimeImmutable {
        if (null !== $request->windowFrom) {
            return $request->windowFrom;
        }

        $since = $cursorSince ?? $now->modify(sprintf('-%d hours', self::DEFAULT_LOOKBACK_HOURS));

        return $since->modify(sprintf('-%d minutes', self::WINDOW_OVERLAP_MINUTES));
    }

    /**
     * @return array{since: ?\DateTimeImmutable, next: ?int, floor: ?\DateTimeImmutable}
     */
    private function decodeCursor(?string $cursorValue): array
    {
        $empty = ['since' => null, 'next' => null, 'floor' => null];

        if (null === $cursorValue || '' === trim($cursorValue)) {
            return $empty;
        }

        try {
            $payload = json_decode($cursorValue, true, 512, \JSON_THROW_ON_ERROR);
            if (!is_array($payload) || !is_string($payload['since'] ?? null)) {
                return $empty;
            }

            $since = new \DateTimeImmutable($payload['since']);
            $next = $payload['next'] ?? null;
            $floor = is_string($payload['floor'] ?? null) ? new \DateTimeImmutable($payload['floor']) : null;

            if (!is_int($next) || $next < 0) {
                return ['since' => $since, 'next' => null, 'floor' => null];
            }

            return ['since' => $since, 'next' => $next, 'floor' => $floor];
        } catch (\Throwable) {
            // Нечитаемый курсор не должен останавливать загрузку: считаем, что
            // позиции нет, и берём окно по умолчанию.
            return $empty;
        }
    }

    /**
     * Границы окна кодируются полностью, до секунд: округление склеило бы
     * разные окна в один externalId, и при повторе чанки выглядели бы
     * версиями одной raw-записи.
     */
    private function windowKey(string $resourceType, \DateTimeImmutable $since, \DateTimeImmutable $to, int $startNext): string
    {
        $utc = new \DateTimeZone('UTC');

        return sprintf(
            '%s:window-%s-%s:next-%d',
            $resourceType,
            $since->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
            $to->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
            $startNext,
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
