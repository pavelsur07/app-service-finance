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
use App\Ingestion\Exception\MalformedConnectorResponseException;
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

    /**
     * Курсор сериализуется С микросекундами.
     *
     * DATE_ATOM их отбрасывает, и отметка `11:30:00.123456` сохранялась бы как
     * `11:30:00`: на следующем обходе та же строка снова оказывалась строго
     * новее курсора, и при отсутствии более поздних строк он вечно возвращался
     * бы к той же секунде, перечитывая одно и то же окно.
     */
    private const CURSOR_FORMAT = 'Y-m-d\TH:i:s.uP';

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
            // Потолок окна заморожен на первой странице и переносится через
            // все продолжения.
            $ceiling = $state['ceiling'] ?? $now;
        } else {
            $since = $this->resolveSince($request, $now, $cursorSince);
            $next = 0;
            $floor = $cursorSince;
            $ceiling = $now;
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
            ++$pages;

            if (!$page->hasMore) {
                break;
            }

            // Курсор постраничности обязан РАСТИ.
            //
            // Не сдвинувшийся `next` на непустой странице означал бы, что
            // следующий запрос вернёт то же самое: обход крутил бы одну
            // страницу до лимита, а затем создавал продолжение с тем же
            // токеном — и так бесконечно, потому что персистентный курсор на
            // ветке продолжения не двигается. Такой ответ считаем испорченным.
            if (null === $page->nextToken || $page->nextToken <= $next) {
                throw new MalformedConnectorResponseException(sprintf('WB orders pagination cursor must grow: got %s after %d.', var_export($page->nextToken, true), $next));
            }

            $next = $page->nextToken;

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
            // Ключ чанка строится по ЗАМОРОЖЕННОМУ окну, а не по «сейчас».
            //
            // Продолжение может быть обработано повторно (ретрай очереди), и
            // тогда $since и $startNext прежние, а $now другое: тот же
            // логический чанк получил бы другой externalId, дедуп по версиям
            // окна не сработал бы, а уникальность события журнала включает
            // rawRecordId — то есть одно и то же наблюдение дало бы лишнюю
            // строку в журнале. fetchedAt при этом остаётся настоящим
            // временем скачивания: это момент наблюдения, а не идентичность.
            externalId: $this->windowKey($request->resourceType, $since, $ceiling, $startNext),
            syncJobId: $request->syncJobId,
            fetchedAt: $now,
            rows: [] === $rows ? [$this->emptyMarker($request->resourceType, $since, $ceiling)] : $rows,
        );

        if ($hasMorePages) {
            return new PullResult(
                rawBatch: $batch,
                nextCursorValue: json_encode(array_filter([
                    'since' => $since->format(self::CURSOR_FORMAT),
                    'next' => $next,
                    'floor' => $floor?->format(self::CURSOR_FORMAT),
                    'ceiling' => $ceiling->format(self::CURSOR_FORMAT),
                ], static fn (mixed $v): bool => null !== $v), \JSON_THROW_ON_ERROR),
                hasMore: true,
                continuationDelaySeconds: 1,
            );
        }

        // Окно дочитано, и курсор переезжает на ПОТОЛОК — время начала обхода,
        // а не время его окончания.
        //
        // Длинная пагинация с продолжениями и ретраями идёт минутами, а то и
        // дольше. Взять «сейчас» последнего вызова значило бы объявить
        // прочитанным всё до этого момента, тогда как ранние страницы
        // снимались раньше: заказ, созданный в середине обхода и оказавшийся
        // на уже пройденной позиции, не попал бы ни в эту цепочку, ни в
        // следующее окно. Перекрытие в 15 минут такой разрыв не покрывает.
        //
        // Перечитать данные новее потолка на следующем обходе дешевле, чем
        // потерять их: апсерт заказа идемпотентен.
        return new PullResult(
            rawBatch: $batch,
            nextCursorValue: json_encode(
                ['since' => max($ceiling, $floor ?? $ceiling)->format(self::CURSOR_FORMAT)],
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

        // Водяной знак — максимальный lastChangeDate СРЕДИ НОВЫХ строк.
        //
        // flag=0 отдаёт поток изменений, и следующий обход обязан начаться
        // ровно там, где кончился предыдущий. Взять вместо этого «сейчас»
        // значило бы пропустить изменения, которые WB проштампует временем
        // между максимумом и моментом ответа.
        //
        // Считать максимум по ВСЕМ строкам нельзя: окно берётся с перекрытием
        // назад, поэтому ответ почти всегда непуст — в нём лежат те же строки,
        // что и в прошлый раз. Их максимум не превышает курсора, и тот
        // застревал бы навсегда, а окно росло бы с каждым часом.
        //
        // Если новых строк нет, WB тем самым сказал, что после курсора
        // изменений не было, и курсор безопасно переезжает на время запроса.
        // Верхняя граница — время запроса.
        //
        // Одна ошибочно будущая отметка (сдвиг часов у источника, аномальная
        // строка) иначе становилась бы курсором навсегда: следующее окно
        // начиналось бы позже реальных изменений, а пустые ответы не смогли бы
        // это исправить, потому что курсор назад не едет.
        // Нижняя граница — прежний курсор, а на первом обходе начало окна.
        //
        // Без неё `$after` равнялся null, и знак мог уехать к отметке РАНЬШЕ
        // фактически запрошенного окна: ответ statistics шире запроса, и одна
        // старая строка отбросила бы курсор назад, заставив перечитывать
        // историю.
        $lowerBound = $cursorSince ?? $since;

        // Рассчитанный знак старше времени запроса и потому побеждает его.
        //
        // Через max() он не проходил бы на ПЕРВОМ обходе: без курсора второй
        // аргумент равен `сейчас`, а $fresh по определению не позже, поэтому
        // курсор всегда уезжал на время запроса. Изменения, проштампованные
        // между фактическим максимумом и ответом, терялись бы — ровно то, ради
        // чего знак и считается по данным. Монотонность сохраняется:
        // maxLastChangeDateAfter() отбирает строки строго позже курсора.
        $fresh = $this->maxLastChangeDateAfter($rows, $lowerBound, $now, $request->resourceType);
        $nextSince = $fresh ?? max($now, $lowerBound);

        return new PullResult(
            rawBatch: $batch,
            nextCursorValue: json_encode(['since' => $nextSince->format(self::CURSOR_FORMAT)], \JSON_THROW_ON_ERROR),
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
     * Максимальный lastChangeDate строго ПОЗЖЕ границы.
     *
     * Граница — прежнее значение курсора, а не начало окна: окно сдвинуто
     * назад на перекрытие, и строки внутри перекрытия — это повторы, а не
     * новости.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function maxLastChangeDateAfter(
        array $rows,
        \DateTimeImmutable $after,
        \DateTimeImmutable $ceiling,
        string $resourceType,
    ): ?\DateTimeImmutable {
        $max = null;
        foreach ($rows as $row) {
            if (true === ($row['_ingestion_empty'] ?? null)) {
                continue;
            }

            // lastChangeDate — часть протокола, а не необязательное поле.
            //
            // Молчаливый пропуск повреждённой отметки означал бы, что
            // непустой испорченный ответ считается доказанным «изменений
            // нет»: курсор уехал бы на время запроса и закрыл непрочитанный
            // участок окна навсегда.
            $parsed = WbOrderDateParser::parseStatisticsInstant($row['lastChangeDate'] ?? null);
            if (null === $parsed) {
                throw new MalformedConnectorResponseException(sprintf('WB %s row has no parsable lastChangeDate.', $resourceType));
            }

            if ($parsed <= $after) {
                continue;
            }

            // Строка из будущего сама по себе сохраняется, но водяной знак ею
            // не двигается: иначе одна аномалия отравила бы курсор.
            if ($parsed > $ceiling) {
                continue;
            }

            if (null === $max || $parsed > $max) {
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
     * @return array{since: ?\DateTimeImmutable, next: ?int, floor: ?\DateTimeImmutable, ceiling: ?\DateTimeImmutable}
     */
    private function decodeCursor(?string $cursorValue): array
    {
        $empty = ['since' => null, 'next' => null, 'floor' => null, 'ceiling' => null];

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
            $ceiling = is_string($payload['ceiling'] ?? null) ? new \DateTimeImmutable($payload['ceiling']) : null;

            if (!is_int($next) || $next < 0) {
                return ['since' => $since, 'next' => null, 'floor' => null, 'ceiling' => null];
            }

            return ['since' => $since, 'next' => $next, 'floor' => $floor, 'ceiling' => $ceiling];
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
