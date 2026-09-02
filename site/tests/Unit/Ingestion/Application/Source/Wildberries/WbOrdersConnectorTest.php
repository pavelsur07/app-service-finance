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
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertSame(WbOrdersConnector::MAX_PAGES_PER_PULL * 10, $decoded['next']);
        $this->assertCursorInstant('2026-09-01T10:45:00+00:00', $result->nextCursorValue);
        $this->assertCursorInstant('2026-09-01T11:00:00+00:00', $result->nextCursorValue, 'floor');
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

        $this->assertCursorInstant('2026-09-01T18:00:00+00:00', $result->nextCursorValue);
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
        $this->assertCursorInstant('2026-09-01T11:30:00+00:00', $result->nextCursorValue);
    }

    /**
     * Пустой ответ означает, что после `since` записей нет: курсор безопасно
     * переезжает на время запроса, иначе окно росло бы бесконечно.
     */
    public function testStatisticsWithoutRowsAdvancesToRequestTime(): void
    {
        $result = $this->connector->pull($this->request(WbResourceType::ORDERS_STATISTICS, '{"since":"2026-09-01T09:00:00+00:00"}'));

        $this->assertCursorInstant('2026-09-01T12:00:00+00:00', $result->nextCursorValue);
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

        // Новых изменений нет — курсор идёт к времени запроса.
        $this->assertCursorInstant('2026-09-01T12:00:00+00:00', $result->nextCursorValue);
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
        self::assertSame('2026-09-01T11:00:00.000000+00:00', $decoded['since']);
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

    /**
     * Регрессия: курсор ставился на «сейчас» ПОСЛЕДНЕГО вызова цепочки.
     *
     * Длинная пагинация с продолжениями идёт минутами: заказ, созданный в
     * середине обхода и оказавшийся на уже пройденной позиции, не попадал ни в
     * эту цепочку, ни в следующее окно — перекрытие в 15 минут такой разрыв не
     * покрывает. Потолок замораживается на первой странице.
     */
    public function testMarketplaceCursorStopsAtTheFrozenCeiling(): void
    {
        $pages = [];
        for ($i = 0; $i < WbOrdersConnector::MAX_PAGES_PER_PULL; ++$i) {
            $pages[] = new WbOrdersPage([['id' => $i, 'rid' => 'r-'.$i]], true, ($i + 1) * 10);
        }
        $this->client->queueMarketplace(...$pages);

        $first = $this->connector->pull($this->request(WbResourceType::ORDERS_MARKETPLACE, '{"since":"2026-09-01T11:00:00+00:00"}'));
        $this->assertCursorInstant('2026-09-01T12:00:00+00:00', $first->nextCursorValue, 'ceiling');

        // Продолжение доигрывается спустя час.
        $client = new FakeWbOrdersClient();
        $client->queueMarketplace(new WbOrdersPage([], false, 999));
        $later = new WbOrdersConnector($client, new MockClock('2026-09-01 13:00:00'));
        $final = $later->pull($this->request(WbResourceType::ORDERS_MARKETPLACE, (string) $first->nextCursorValue));

        $this->assertCursorInstant('2026-09-01T12:00:00+00:00', $final->nextCursorValue);
    }

    /**
     * Регрессия: одна ошибочно будущая отметка становилась курсором навсегда.
     * Следующее окно начиналось бы позже реальных изменений, а пустые ответы
     * не смогли бы это исправить — курсор назад не едет.
     */
    public function testFutureLastChangeDateDoesNotPoisonTheWatermark(): void
    {
        $this->client->queueStatistics(new WbOrdersPage([
            ['srid' => 's-1', 'lastChangeDate' => '2026-09-01T14:00:00'],
            // 18:00 по Москве — это 15:00 UTC, на три часа впереди часов.
            ['srid' => 's-2', 'lastChangeDate' => '2026-09-01T18:00:00'],
        ], false));

        $result = $this->connector->pull($this->request(WbResourceType::ORDERS_STATISTICS, '{"since":"2026-09-01T09:00:00+00:00"}'));

        // 14:00 по Москве — это 11:00 UTC: берётся максимум среди НЕ будущих.
        $decoded = json_decode((string) $result->nextCursorValue, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('2026-09-01T11:00:00.000000+00:00', $decoded['since']);

        // Сама строка при этом не теряется.
        self::assertNotNull($result->rawBatch);
        self::assertCount(2, iterator_to_array($result->rawBatch->rows));
    }

    /**
     * Регрессия: ключ чанка строился по «сейчас». Продолжение может быть
     * обработано повторно (ретрай очереди), и тогда тот же логический чанк
     * получал другой externalId: дедуп по версиям окна не срабатывал, а
     * уникальность события журнала включает rawRecordId — одно и то же
     * наблюдение давало лишнюю строку.
     */
    public function testContinuationChunkIdentityDoesNotDependOnRetryTime(): void
    {
        $cursor = json_encode([
            'since' => '2026-09-01T10:45:00+00:00',
            'next' => 500,
            'ceiling' => '2026-09-01T12:00:00+00:00',
        ], \JSON_THROW_ON_ERROR);

        $this->client->queueMarketplace(new WbOrdersPage([['id' => 1, 'rid' => 'r-1']], false, 600));
        $first = $this->connector->pull($this->request(WbResourceType::ORDERS_MARKETPLACE, $cursor));

        // Тот же курсор, но повтор случился спустя час.
        $client = new FakeWbOrdersClient();
        $client->queueMarketplace(new WbOrdersPage([['id' => 1, 'rid' => 'r-1']], false, 600));
        $retry = (new WbOrdersConnector($client, new MockClock('2026-09-01 13:00:00')))
            ->pull($this->request(WbResourceType::ORDERS_MARKETPLACE, $cursor));

        self::assertNotNull($first->rawBatch);
        self::assertNotNull($retry->rawBatch);
        self::assertSame($first->rawBatch->externalId, $retry->rawBatch->externalId);
    }

    /**
     * Регрессия: на ПЕРВОМ обходе рассчитанный водяной знак игнорировался.
     * Без курсора выражение max($fresh, $now) всегда давало «сейчас», потому
     * что $fresh по определению не позже, — и изменения, проштампованные
     * между фактическим максимумом и ответом, терялись бы. Ровно то, ради чего
     * знак и считается по данным.
     */
    public function testFirstStatisticsRunUsesTheCalculatedWatermark(): void
    {
        // Первый обход просит с 10:45 UTC (13:45 МСК) по 12:00 UTC (15:00 МСК).
        $this->client->queueStatistics(new WbOrdersPage([
            ['srid' => 's-1', 'lastChangeDate' => '2026-09-01T13:50:00'],
            ['srid' => 's-2', 'lastChangeDate' => '2026-09-01T14:30:00'],
        ], false));

        $result = $this->connector->pull($this->request(WbResourceType::ORDERS_STATISTICS, null));

        // 14:30 по Москве — это 11:30 UTC, заметно раньше часов 12:00.
        $this->assertCursorInstant('2026-09-01T11:30:00+00:00', $result->nextCursorValue);
    }

    /**
     * Регрессия: на первом обходе нижней границы не было вовсе, и строка
     * старше запрошенного окна отбрасывала курсор назад. Ответ statistics
     * шире запроса, поэтому такие строки в нём есть всегда, и это заставляло
     * бы перечитывать историю.
     */
    public function testRowOlderThanTheRequestedWindowDoesNotRewindTheCursor(): void
    {
        $this->client->queueStatistics(new WbOrdersPage([
            // 10:30 МСК — это 07:30 UTC, задолго до начала окна 10:45 UTC.
            ['srid' => 's-1', 'lastChangeDate' => '2026-09-01T10:30:00'],
        ], false));

        $result = $this->connector->pull($this->request(WbResourceType::ORDERS_STATISTICS, null));

        $this->assertCursorInstant('2026-09-01T12:00:00+00:00', $result->nextCursorValue);
    }

    /**
     * Регрессия: DATE_ATOM отбрасывал микросекунды, и отметка с дробной частью
     * вечно оставалась «новее курсора» — обход перечитывал одно и то же окно.
     */
    public function testFractionalWatermarkSurvivesTheCursorRoundTrip(): void
    {
        $this->client->queueStatistics(new WbOrdersPage([
            ['srid' => 's-1', 'lastChangeDate' => '2026-09-01T14:30:00.123456'],
        ], false));

        $first = $this->connector->pull($this->request(WbResourceType::ORDERS_STATISTICS, null));
        $this->assertCursorInstant('2026-09-01T11:30:00.123456+00:00', $first->nextCursorValue);

        // Второй обход видит ту же строку — она больше не свежая.
        $this->client->queueStatistics(new WbOrdersPage([
            ['srid' => 's-1', 'lastChangeDate' => '2026-09-01T14:30:00.123456'],
        ], false));
        $second = $this->connector->pull($this->request(WbResourceType::ORDERS_STATISTICS, (string) $first->nextCursorValue));

        $this->assertCursorInstant('2026-09-01T12:00:00+00:00', $second->nextCursorValue);
    }

    /**
     * lastChangeDate — часть протокола. Молчаливый пропуск повреждённой
     * отметки означал бы, что непустой испорченный ответ считается доказанным
     * «изменений нет»: курсор уехал бы вперёд и закрыл непрочитанный участок.
     *
     * @param array<string, mixed> $row
     */
    #[DataProvider('brokenChangeDateProvider')]
    public function testUnparsableLastChangeDateIsMalformed(array $row): void
    {
        $this->client->queueStatistics(new WbOrdersPage([$row], false));

        $this->expectException(MalformedConnectorResponseException::class);
        $this->connector->pull($this->request(WbResourceType::ORDERS_STATISTICS, null));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function brokenChangeDateProvider(): iterable
    {
        yield 'отметки нет' => [['srid' => 's-1']];
        yield 'отметка не строка' => [['srid' => 's-1', 'lastChangeDate' => 123]];
        yield 'отметка не разбирается' => [['srid' => 's-1', 'lastChangeDate' => 'вчера']];
        yield 'нулевая заглушка' => [['srid' => 's-1', 'lastChangeDate' => '0001-01-01T00:00:00']];
    }

    /**
     * Сравнение по АБСОЛЮТНОМУ моменту, а не по строке.
     *
     * Курсор пишется в зоне приложения, поэтому `15:00+03:00` и `12:00Z` —
     * одно и то же время. Сравнение строк проверяло бы соглашение о записи, а
     * не корректность позиции.
     */
    private function assertCursorInstant(string $expected, ?string $cursorValue, string $key = 'since'): void
    {
        self::assertNotNull($cursorValue);
        $decoded = json_decode($cursorValue, true, 512, \JSON_THROW_ON_ERROR);

        self::assertArrayHasKey($key, $decoded);
        self::assertSame(
            (new \DateTimeImmutable($expected))->getTimestamp(),
            (new \DateTimeImmutable($decoded[$key]))->getTimestamp(),
        );
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
