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
    public const MAX_PAGES_PER_PULL = 20;

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
        $state = $this->decodeCursor($request->cursorValue);
        $cursorSince = $state['since'];

        // Продолжение несёт ЗАМОРОЖЕННОЕ окно и смещение.
        //
        // Без этого остаток окна терялся безвозвратно: после лимита страниц
        // наружу уходил hasMore=true, но состояние содержало только `since`,
        // уже продвинутый до конца окна. Следующий вызов начинал с offset=0 в
        // НОВОМ окне, и заказы с 21-й страницы не читал никто и никогда.
        // Особенно больно на первом семидневном посеве.
        $isContinuation = null !== $state['to'];
        if ($isContinuation) {
            $since = $cursorSince ?? $now->modify('-1 hour');
            $to = $state['to'];
            $offset = $state['offset'];
        } else {
            $since = $this->resolveSince($request, $now, $cursorSince);
            $to = $request->windowTo ?? $now;
            $offset = 0;
        }

        $rows = [];
        $startOffset = $offset;
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
            externalId: $this->windowKey($scheme, $since, $to, $startOffset),
            syncJobId: $request->syncJobId,
            fetchedAt: $now,
            rows: [] === $rows ? [$this->emptyMarker($request->resourceType, $since, $to)] : $rows,
        );

        if ($hasMorePages) {
            // Персистентный курсор здесь НЕ двигается: RunSyncChunkHandler на
            // ветке продолжения не вызывает updateCursor, а передаёт это
            // значение следующим сообщением. Окно остаётся тем же, пока не
            // дочитано до конца.
            return new PullResult(
                rawBatch: $batch,
                nextCursorValue: json_encode([
                    'since' => $since->format(\DATE_ATOM),
                    'to' => $to->format(\DATE_ATOM),
                    'offset' => $offset,
                ], \JSON_THROW_ON_ERROR),
                hasMore: true,
                continuationDelaySeconds: 1,
            );
        }

        // Курсор не едет назад. IngestCursor::advance() монотонность не
        // проверяет, а откат заставил бы перекачивать одно и то же вечно.
        //
        // Сравнение именно с ИСХОДНЫМ значением курсора, а не с $since:
        // $since уже сдвинут назад на перекрытие окна, и вернуть его значило
        // бы каждый час откатывать позицию на четверть часа.
        $nextSince = max($to, $isContinuation ? $to : ($cursorSince ?? $to));

        return new PullResult(
            rawBatch: $batch,
            nextCursorValue: json_encode(['since' => $nextSince->format(\DATE_ATOM)], \JSON_THROW_ON_ERROR),
            hasMore: false,
            continuationDelaySeconds: null,
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

    /**
     * @return array{since: ?\DateTimeImmutable, to: ?\DateTimeImmutable, offset: int}
     */
    private function decodeCursor(?string $cursorValue): array
    {
        $empty = ['since' => null, 'to' => null, 'offset' => 0];

        if (null === $cursorValue || '' === trim($cursorValue)) {
            return $empty;
        }

        try {
            $payload = json_decode($cursorValue, true, 512, \JSON_THROW_ON_ERROR);
            if (!is_array($payload) || !is_string($payload['since'] ?? null)) {
                return $empty;
            }

            $since = new \DateTimeImmutable($payload['since']);

            // Продолжение опознаётся по паре `to` + целочисленный `offset`.
            // Половинчатое состояние (одно без другого) продолжением не
            // считаем: лучше пройти окно заново, чем читать с произвольного
            // смещения.
            $to = is_string($payload['to'] ?? null) ? new \DateTimeImmutable($payload['to']) : null;
            $offset = $payload['offset'] ?? null;
            if (null === $to || !is_int($offset) || $offset < 0) {
                return ['since' => $since, 'to' => null, 'offset' => 0];
            }

            return ['since' => $since, 'to' => $to, 'offset' => $offset];
        } catch (\Throwable) {
            // Нечитаемый курсор не должен останавливать загрузку: считаем, что
            // позиции нет, и берём окно по умолчанию.
            return $empty;
        }
    }

    /**
     * Детерминированный ключ окна вместо порядкового номера: неизменившееся
     * окно даёт тот же hash и не создаёт нового объекта в хранилище.
     */
    private function windowKey(
        IngestOrderScheme $scheme,
        \DateTimeImmutable $since,
        \DateTimeImmutable $to,
        int $startOffset,
    ): string {
        // Смещение — часть ключа: чанки одного окна несут разные строки, и под
        // общим externalId второй чанк выглядел бы новой версией первого.
        return sprintf(
            '%s:window-%s-%s:offset-%d',
            $scheme->value,
            $since->format('Y-m-d\TH'),
            $to->format('Y-m-d\TH'),
            $startOffset,
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
