<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application\Source\Wildberries;

use App\Ingestion\Application\DTO\PullRequest;
use App\Ingestion\Application\DTO\PushRequest;
use App\Ingestion\Application\Source\Wildberries\WbOrdersConnector;
use App\Ingestion\Application\Source\Wildberries\WbResourceType;
use App\Ingestion\Enum\Capability;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Exception\MalformedConnectorResponseException;
use App\Ingestion\Exception\UnsupportedCapabilityException;
use App\Ingestion\Infrastructure\Api\Wildberries\WbOrdersPage;
use App\Tests\Integration\Ingestion\Fixtures\FakeWbOrdersClient;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;

final class WbOrdersConnectorTest extends TestCase
{
    private FakeWbOrdersClient $client;
    private WbOrdersConnector $connector;

    protected function setUp(): void
    {
        $this->client = new FakeWbOrdersClient();
        $this->connector = new WbOrdersConnector($this->client, new MockClock('2026-09-01 12:00:00'));
    }

    public function testDeclaresBothOrderResourcesAndPullOnly(): void
    {
        self::assertSame(IngestSource::WILDBERRIES, $this->connector->source());
        self::assertSame(
            [WbResourceType::ORDERS_MARKETPLACE, WbResourceType::ORDERS_STATISTICS],
            $this->connector->resourceTypes(),
        );
        self::assertSame([Capability::CAN_PULL], $this->connector->capabilities());
    }

    public function testPushIsNotSupported(): void
    {
        $this->expectException(UnsupportedCapabilityException::class);
        $this->connector->push(new PushRequest(
            companyId: Uuid::uuid7()->toString(),
            connectionRef: 'connection-1',
            documentType: WbResourceType::ORDERS_MARKETPLACE,
            payload: [],
            idempotencyKey: 'key-1',
        ));
    }

    /**
     * Признак «есть ещё» у marketplace — непустая страница: документированного
     * флага у эндпоинта нет.
     */
    public function testMarketplacePaginatesUntilEmptyPage(): void
    {
        $this->client->queueMarketplace(
            new WbOrdersPage([['id' => 1, 'rid' => 'r-1']], true, 100),
            new WbOrdersPage([['id' => 2, 'rid' => 'r-2']], true, 200),
            new WbOrdersPage([], false, 200),
        );

        $result = $this->connector->pull($this->request(WbResourceType::ORDERS_MARKETPLACE, '{"since":"2026-09-01T11:00:00+00:00"}'));

        self::assertNotNull($result->rawBatch);
        self::assertCount(2, iterator_to_array($result->rawBatch->rows));

        $orderCalls = array_values(array_filter($this->client->calls, static fn (array $c): bool => 'orders' === $c['endpoint']));
        self::assertSame([0, 100, 200], array_column($orderCalls, 'next'));
    }

    /**
     * Окно берётся с перекрытием назад: заказ, созданный за миг до прошлого
     * запроса, иначе не попал бы ни в одно окно.
     */
    public function testMarketplaceWindowOverlapsBackwards(): void
    {
        $this->connector->pull($this->request(WbResourceType::ORDERS_MARKETPLACE, '{"since":"2026-09-01T11:00:00+00:00"}'));

        self::assertSame('2026-09-01T10:45:00+00:00', $this->client->calls[0]['since']);
    }

    /**
     * Статусы живут на отдельном эндпоинте: маппер обязан получить строку, в
     * которой уже есть всё нужное.
     */
    public function testStatusesAreAttachedToOrderRows(): void
    {
        $this->client->queueMarketplace(new WbOrdersPage([['id' => 5, 'rid' => 'r-5']], false, 0));
        $this->client->setStatuses([5 => ['id' => 5, 'supplierStatus' => 'complete', 'wbStatus' => 'sorted']]);

        $result = $this->connector->pull($this->request(WbResourceType::ORDERS_MARKETPLACE, null));

        self::assertNotNull($result->rawBatch);
        $rows = iterator_to_array($result->rawBatch->rows);
        self::assertSame('sorted', $rows[0]['_ingestion_status']['wbStatus']);
    }

    /**
     * Заказ, для которого WB статуса не отдал, не должен пропадать: он
     * сохраняется без подмешанного блока и деградирует уже на нормализации.
     */
    public function testOrderWithoutStatusIsStillStored(): void
    {
        $this->client->queueMarketplace(new WbOrdersPage([['id' => 5, 'rid' => 'r-5']], false, 0));
        $this->client->setStatuses([]);

        $result = $this->connector->pull($this->request(WbResourceType::ORDERS_MARKETPLACE, null));

        self::assertNotNull($result->rawBatch);
        $rows = iterator_to_array($result->rawBatch->rows);
        self::assertArrayNotHasKey('_ingestion_status', $rows[0]);
    }

    public function testNoStatusCallWhenThereAreNoOrders(): void
    {
        $this->connector->pull($this->request(WbResourceType::ORDERS_MARKETPLACE, null));

        self::assertSame([], array_values(array_filter(
            $this->client->calls,
            static fn (array $c): bool => 'status' === $c['endpoint'],
        )));
    }

    /**
     * Продолжение несёт замороженное окно и курсор постраничности WB: иначе
     * следующий вызов начал бы новое окно с нулевого next и остаток потерялся
     * бы безвозвратно.
     */
    public function testMarketplaceContinuationCarriesWindowAndNextToken(): void
    {
        $pages = [];
        for ($i = 0; $i < WbOrdersConnector::MAX_PAGES_PER_PULL; ++$i) {
            $pages[] = new WbOrdersPage([['id' => $i, 'rid' => 'r-'.$i]], true, ($i + 1) * 10);
        }
        $this->client->queueMarketplace(...$pages);

        $result = $this->connector->pull($this->request(WbResourceType::ORDERS_MARKETPLACE, '{"since":"2026-09-01T11:00:00+00:00"}'));

        self::assertTrue($result->hasMore);
        $decoded = json_decode((string) $result->nextCursorValue, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('2026-09-01T10:45:00+00:00', $decoded['since']);
        self::assertSame(WbOrdersConnector::MAX_PAGES_PER_PULL * 10, $decoded['next']);
        self::assertSame('2026-09-01T11:00:00+00:00', $decoded['floor']);
    }

    public function testMarketplaceContinuationResumesFromStoredNextToken(): void
    {
        $this->client->queueMarketplace(new WbOrdersPage([], false, 999));

        $this->connector->pull($this->request(WbResourceType::ORDERS_MARKETPLACE, json_encode([
            'since' => '2026-09-01T10:45:00+00:00',
            'next' => 500,
            'floor' => '2026-09-01T11:00:00+00:00',
        ], \JSON_THROW_ON_ERROR)));

        // Окно то же, перекрытие повторно не вычитается, чтение продолжается.
        self::assertSame('2026-09-01T10:45:00+00:00', $this->client->calls[0]['since']);
        self::assertSame(500, $this->client->calls[0]['next']);
    }

    /**
     * Курсор не едет назад: пол монотонности переносится через продолжение.
     */
    public function testMarketplaceCursorNeverMovesBackwards(): void
    {
        $this->client->queueMarketplace(new WbOrdersPage([], false, 0));

        $result = $this->connector->pull($this->request(WbResourceType::ORDERS_MARKETPLACE, json_encode([
            'since' => '2026-09-01T10:45:00+00:00',
            'next' => 0,
            'floor' => '2026-09-01T18:00:00+00:00',
        ], \JSON_THROW_ON_ERROR)));

        $decoded = json_decode((string) $result->nextCursorValue, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('2026-09-01T18:00:00+00:00', $decoded['since']);
    }

    /**
     * Водяной знак statistics — максимальный lastChangeDate, а не время
     * запроса: flag=0 отдаёт поток изменений, и следующий обход обязан
     * начаться ровно там, где кончился предыдущий.
     */
    public function testStatisticsWatermarkIsMaxLastChangeDate(): void
    {
        $this->client->queueStatistics(new WbOrdersPage([
            ['srid' => 's-1', 'lastChangeDate' => '2026-09-01T13:00:00'],
            ['srid' => 's-2', 'lastChangeDate' => '2026-09-01T14:30:00'],
            ['srid' => 's-3', 'lastChangeDate' => '2026-09-01T13:45:00'],
        ], false));

        $result = $this->connector->pull($this->request(WbResourceType::ORDERS_STATISTICS, '{"since":"2026-09-01T09:00:00+00:00"}'));

        // 14:30 по Москве — это 11:30 UTC.
        $decoded = json_decode((string) $result->nextCursorValue, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('2026-09-01T11:30:00+00:00', $decoded['since']);
    }

    /**
     * Пустой ответ означает, что после `since` записей нет: курсор безопасно
     * переезжает на время запроса, иначе окно росло бы бесконечно.
     */
    public function testStatisticsWithoutRowsAdvancesToRequestTime(): void
    {
        $result = $this->connector->pull($this->request(WbResourceType::ORDERS_STATISTICS, '{"since":"2026-09-01T09:00:00+00:00"}'));

        $decoded = json_decode((string) $result->nextCursorValue, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('2026-09-01T12:00:00+00:00', $decoded['since']);
    }

    /**
     * Регрессия: окно берётся с перекрытием назад, поэтому ответ почти всегда
     * непуст — в нём лежат те же строки, что и в прошлый раз. Максимум по ВСЕМ
     * строкам не превышал курсора, тот застревал навсегда, а окно росло с
     * каждым часом. Повторы обязаны игнорироваться при расчёте водяного знака.
     */
    public function testOverlapRowsAloneDoNotFreezeTheWatermark(): void
    {
        $this->client->queueStatistics(new WbOrdersPage([
            ['srid' => 's-1', 'lastChangeDate' => '2026-09-01T11:30:00'],
            ['srid' => 's-2', 'lastChangeDate' => '2026-09-01T11:55:00'],
        ], false));

        // Курсор 09:00 UTC = 12:00 по Москве: обе строки внутри перекрытия.
        $result = $this->connector->pull($this->request(WbResourceType::ORDERS_STATISTICS, '{"since":"2026-09-01T09:00:00+00:00"}'));

        $decoded = json_decode((string) $result->nextCursorValue, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('2026-09-01T12:00:00+00:00', $decoded['since'], 'Новых изменений нет — курсор идёт к времени запроса.');
    }

    /**
     * Новая строка среди повторов двигает знак ровно на себя, а не на «сейчас»:
     * иначе потерялись бы изменения, проштампованные между ней и ответом.
     */
    public function testFreshRowAmongOverlapRowsSetsTheWatermark(): void
    {
        $this->client->queueStatistics(new WbOrdersPage([
            ['srid' => 's-1', 'lastChangeDate' => '2026-09-01T11:30:00'],
            ['srid' => 's-2', 'lastChangeDate' => '2026-09-01T14:00:00'],
        ], false));

        $result = $this->connector->pull($this->request(WbResourceType::ORDERS_STATISTICS, '{"since":"2026-09-01T09:00:00+00:00"}'));

        // 14:00 по Москве — это 11:00 UTC.
        $decoded = json_decode((string) $result->nextCursorValue, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('2026-09-01T11:00:00+00:00', $decoded['since']);
    }

    public function testStatisticsMakesExactlyOneCall(): void
    {
        $this->client->queueStatistics(new WbOrdersPage([['srid' => 's-1', 'lastChangeDate' => '2026-09-01T13:00:00']], false));

        $result = $this->connector->pull($this->request(WbResourceType::ORDERS_STATISTICS, null));

        self::assertCount(1, $this->client->calls);
        self::assertFalse($result->hasMore);
    }

    /**
     * Пустое окно всё равно фиксируется в raw: «за этот час изменений не
     * было» — это факт, а не отсутствие данных.
     */
    public function testEmptyWindowIsStillRecorded(): void
    {
        $result = $this->connector->pull($this->request(WbResourceType::ORDERS_STATISTICS, null));

        self::assertNotNull($result->rawBatch);
        self::assertCount(1, iterator_to_array($result->rawBatch->rows));
    }

    /**
     * Границы окна кодируются полностью: округление склеило бы разные окна в
     * один externalId, и чанки выглядели бы версиями одной raw-записи.
     */
    public function testExternalIdCarriesFullWindowAndPageToken(): void
    {
        $result = $this->connector->pull($this->request(WbResourceType::ORDERS_STATISTICS, '{"since":"2026-09-01T11:00:00+00:00"}'));

        self::assertNotNull($result->rawBatch);
        self::assertSame(
            'wildberries_orders_statistics:window-2026-09-01T10:45:00Z-2026-09-01T12:00:00Z:next-0',
            $result->rawBatch->externalId,
        );
    }

    /**
     * Регрессия: не сдвинувшийся `next` на непустой странице означает, что
     * следующий запрос вернёт то же самое. Обход крутил бы одну страницу до
     * лимита, а затем создавал продолжение с тем же токеном — и так
     * бесконечно, потому что на ветке продолжения персистентный курсор не
     * двигается.
     */
    public function testNonGrowingPageCursorIsMalformed(): void
    {
        $this->client->queueMarketplace(
            new WbOrdersPage([['id' => 1, 'rid' => 'r-1']], true, 100),
            new WbOrdersPage([['id' => 2, 'rid' => 'r-2']], true, 100),
        );

        $this->expectException(MalformedConnectorResponseException::class);
        $this->connector->pull($this->request(WbResourceType::ORDERS_MARKETPLACE, null));
    }

    public function testMissingPageCursorIsMalformed(): void
    {
        $this->client->queueMarketplace(new WbOrdersPage([['id' => 1, 'rid' => 'r-1']], true, null));

        $this->expectException(MalformedConnectorResponseException::class);
        $this->connector->pull($this->request(WbResourceType::ORDERS_MARKETPLACE, null));
    }

    private function request(string $resourceType, ?string $cursorValue): PullRequest
    {
        return new PullRequest(
            companyId: Uuid::uuid7()->toString(),
            connectionRef: 'connection-1',
            shopRef: 'shop-main',
            resourceType: $resourceType,
            cursorValue: $cursorValue,
            windowFrom: null,
            windowTo: null,
            syncJobId: Uuid::uuid7()->toString(),
        );
    }
}
