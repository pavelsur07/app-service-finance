<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\DTO\PullRequest;
use App\Ingestion\Application\DTO\PushRequest;
use App\Ingestion\Application\Source\Ozon\OzonOrdersConnector;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Enum\Capability;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Exception\UnsupportedCapabilityException;
use App\Ingestion\Infrastructure\Api\Ozon\OzonRawPage;
use App\Tests\Integration\Ingestion\Fixtures\FakeOzonOrdersClient;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;

final class OzonOrdersConnectorTest extends TestCase
{
    private FakeOzonOrdersClient $client;
    private OzonOrdersConnector $connector;

    protected function setUp(): void
    {
        $this->client = new FakeOzonOrdersClient();
        $this->connector = new OzonOrdersConnector(
            $this->client,
            new MockClock('2026-09-01 12:00:00'),
        );
    }

    public function testDeclaresBothOrderResourcesAndPullOnly(): void
    {
        self::assertSame(IngestSource::OZON, $this->connector->source());
        self::assertSame(
            [OzonResourceType::ORDERS_FBO, OzonResourceType::ORDERS_FBS],
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
            documentType: OzonResourceType::ORDERS_FBO,
            payload: [],
            idempotencyKey: 'key-1',
        ));
    }

    /**
     * Пагинация съедается внутри одного pull: наружу торчит только часовой
     * курсор, а не смещение внутри окна.
     */
    public function testPaginatesUntilThePageIsNotFull(): void
    {
        $this->client->queue(
            new OzonRawPage($this->postings(OzonOrdersConnector::PAGE_LIMIT), true, null, []),
            new OzonRawPage($this->postings(3), false, null, []),
        );

        $result = $this->connector->pull($this->request('{"since":"2026-09-01T10:00:00+00:00"}'));

        self::assertNotNull($result->rawBatch);
        self::assertCount(OzonOrdersConnector::PAGE_LIMIT + 3, iterator_to_array($result->rawBatch->rows));
        self::assertCount(2, $this->client->calls);
        self::assertSame(0, $this->client->calls[0]['offset']);
        self::assertSame(OzonOrdersConnector::PAGE_LIMIT, $this->client->calls[1]['offset']);
    }

    /**
     * Окно берётся с перекрытием назад: отправление, созданное за миг до
     * прошлого запроса, иначе не попало бы ни в одно окно.
     */
    public function testWindowOverlapsBackwards(): void
    {
        $this->connector->pull($this->request('{"since":"2026-09-01T11:00:00+00:00"}'));

        $this->assertSameInstant('2026-09-01T10:45:00+00:00', $this->client->calls[0]['since']);
        $this->assertSameInstant('2026-09-01T12:00:00+00:00', $this->client->calls[0]['to']);
    }

    /**
     * Курсор не едет назад. IngestCursor::advance() монотонность не проверяет,
     * а у часового инстант-курсора формат её тоже не гарантирует: коннектор,
     * вернувший меньшее значение, заставил бы перекачивать одно и то же вечно.
     */
    public function testCursorNeverMovesBackwards(): void
    {
        $result = $this->connector->pull($this->request('{"since":"2026-09-01T18:00:00+00:00"}'));

        self::assertNotNull($result->nextCursorValue);
        $decoded = json_decode($result->nextCursorValue, true, 512, \JSON_THROW_ON_ERROR);
        self::assertGreaterThanOrEqual('2026-09-01T18:00:00+00:00', $decoded['since']);
    }

    /**
     * externalId — оконный ключ, а не порядковый номер: неизменившееся окно
     * не создаёт новый объект в S3 благодаря дедупу по sha256.
     */
    public function testExternalIdIsDeterministicWindowKey(): void
    {
        $result = $this->connector->pull($this->request('{"since":"2026-09-01T10:00:00+00:00"}'));

        self::assertNotNull($result->rawBatch);
        self::assertSame('fbo:window-2026-09-01T09:45:00Z-2026-09-01T12:00:00Z:offset-0', $result->rawBatch->externalId);
    }

    /**
     * Регрессия: границы окна округлялись до часа, поэтому [09:45,12:00] и
     * [09:30,12:30] давали один externalId — при ретрае до продвижения курсора
     * разные чанки выглядели версиями одной raw-записи.
     */
    public function testWindowsWithinTheSameHourGetDistinctExternalIds(): void
    {
        $first = $this->connector->pull($this->request('{"since":"2026-09-01T10:00:00+00:00"}'));

        $connector = new OzonOrdersConnector($this->client, new MockClock('2026-09-01 12:30:00'));
        $second = $connector->pull($this->request('{"since":"2026-09-01T09:45:00+00:00"}'));

        self::assertNotNull($first->rawBatch);
        self::assertNotNull($second->rawBatch);
        self::assertNotSame($first->rawBatch->externalId, $second->rawBatch->externalId);
    }

    /**
     * Пустое окно всё равно фиксируется в raw: «за этот час заказов не было» —
     * это факт, а не отсутствие данных.
     */
    public function testEmptyWindowIsStillRecorded(): void
    {
        $result = $this->connector->pull($this->request('{"since":"2026-09-01T10:00:00+00:00"}'));

        self::assertNotNull($result->rawBatch);
        self::assertCount(1, iterator_to_array($result->rawBatch->rows));
    }

    /**
     * Регрессия: на старом коде продолжение теряло окно и смещение.
     *
     * Наружу уходил hasMore=true, но состояние содержало только `since`, уже
     * продвинутый до конца окна. Следующий вызов начинал с offset=0 в НОВОМ
     * окне, и заказы после лимита страниц не читал никто и никогда.
     */
    public function testContinuationCarriesFrozenWindowAndOffset(): void
    {
        $pages = [];
        for ($i = 0; $i < OzonOrdersConnector::MAX_PAGES_PER_PULL; ++$i) {
            $pages[] = new OzonRawPage($this->postings(OzonOrdersConnector::PAGE_LIMIT), true, null, []);
        }
        $this->client->queue(...$pages);

        $result = $this->connector->pull($this->request('{"since":"2026-09-01T10:00:00+00:00"}'));

        self::assertTrue($result->hasMore);
        self::assertNotNull($result->nextCursorValue);
        $decoded = json_decode($result->nextCursorValue, true, 512, \JSON_THROW_ON_ERROR);

        // Окно заморожено целиком, а не сведено к одной точке.
        $this->assertSameInstant('2026-09-01T09:45:00+00:00', $decoded['since']);
        $this->assertSameInstant('2026-09-01T12:00:00+00:00', $decoded['to']);
        self::assertSame(
            OzonOrdersConnector::MAX_PAGES_PER_PULL * OzonOrdersConnector::PAGE_LIMIT,
            $decoded['offset'],
        );
    }

    public function testContinuationResumesSameWindowAtStoredOffset(): void
    {
        $this->client->queue(new OzonRawPage($this->postings(2), false, null, []));

        $result = $this->connector->pull($this->request(json_encode([
            'since' => '2026-09-01T09:45:00+00:00',
            'to' => '2026-09-01T12:00:00+00:00',
            'offset' => 20000,
        ], \JSON_THROW_ON_ERROR)));

        // Окно то же самое, перекрытие повторно НЕ вычитается, чтение
        // продолжается с сохранённого смещения.
        $this->assertSameInstant('2026-09-01T09:45:00+00:00', $this->client->calls[0]['since']);
        $this->assertSameInstant('2026-09-01T12:00:00+00:00', $this->client->calls[0]['to']);
        self::assertSame(20000, $this->client->calls[0]['offset']);

        // Чанк — отдельная логическая запись: под общим externalId он выглядел
        // бы новой версией первого чанка и затёр бы его.
        self::assertNotNull($result->rawBatch);
        self::assertSame('fbo:window-2026-09-01T09:45:00Z-2026-09-01T12:00:00Z:offset-20000', $result->rawBatch->externalId);

        // Окно дочитано — курсор наконец уезжает на его конец.
        self::assertFalse($result->hasMore);
        $decoded = json_decode((string) $result->nextCursorValue, true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSameInstant('2026-09-01T12:00:00+00:00', $decoded['since']);
        self::assertArrayNotHasKey('to', $decoded);
    }

    /**
     * Половинчатое состояние не должно читаться с произвольного смещения:
     * без `to` окно неизвестно, и offset относился бы к другому окну.
     */
    public function testCursorWithOffsetButNoWindowStartsFresh(): void
    {
        $this->client->queue(new OzonRawPage($this->postings(1), false, null, []));

        $this->connector->pull($this->request(json_encode([
            'since' => '2026-09-01T10:00:00+00:00',
            'offset' => 20000,
        ], \JSON_THROW_ON_ERROR)));

        self::assertSame(0, $this->client->calls[0]['offset']);
        $this->assertSameInstant('2026-09-01T09:45:00+00:00', $this->client->calls[0]['since']);
    }

    /**
     * Регрессия: через продолжение исходное значение курсора не переносилось,
     * и финальная страница записывала конец окна. Курсор, ушедший вперёд,
     * откатывался назад, и почасовой процесс заново обходил историю.
     */
    public function testContinuationCarriesMonotonicityFloor(): void
    {
        $pages = [];
        for ($i = 0; $i < OzonOrdersConnector::MAX_PAGES_PER_PULL; ++$i) {
            $pages[] = new OzonRawPage($this->postings(OzonOrdersConnector::PAGE_LIMIT), true, null, []);
        }
        $this->client->queue(...$pages);

        // Курсор чуть впереди текущих часов: окно ещё корректно (12:10 минус
        // перекрытие даёт 11:55 <= 12:00), но пол уже опережает конец окна.
        $first = $this->connector->pull($this->request('{"since":"2026-09-01T12:10:00+00:00"}'));

        self::assertTrue($first->hasMore);
        $continuation = json_decode((string) $first->nextCursorValue, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('2026-09-01T12:10:00+00:00', $continuation['floor']);

        // Финальная страница продолжения не должна откатить курсор на конец окна.
        $this->client->queue(new OzonRawPage($this->postings(1), false, null, []));
        $final = $this->connector->pull($this->request((string) $first->nextCursorValue));

        $decoded = json_decode((string) $final->nextCursorValue, true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSameInstant('2026-09-01T12:10:00+00:00', $decoded['since']);
    }

    /**
     * Регрессия: обратное окно уходило в API. Курсор может оказаться впереди
     * текущего момента (перевод часов, ручное продвижение, уже выполненная
     * задача), и тогда since > to. HTTP 400 от Ozon клиент классифицирует как
     * неповторяемый malformed response, и загрузка встала бы вместо того,
     * чтобы дождаться, пока время догонит курсор.
     */
    public function testFutureCursorDoesNotSendInvertedWindowToTheApi(): void
    {
        $result = $this->connector->pull($this->request('{"since":"2026-09-01T18:00:00+00:00"}'));

        self::assertSame([], $this->client->calls, 'За окном нулевой длины запрашивать нечего.');

        // Курсор сохраняется полом монотонности и назад не едет.
        $decoded = json_decode((string) $result->nextCursorValue, true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSameInstant('2026-09-01T18:00:00+00:00', $decoded['since']);
        self::assertFalse($result->hasMore);

        // Факт «за это окно ничего не было» всё равно фиксируется в raw.
        self::assertNotNull($result->rawBatch);
        self::assertCount(1, iterator_to_array($result->rawBatch->rows));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function postings(int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; ++$i) {
            $rows[] = ['posting_number' => 'p-'.$i, 'status' => 'delivering'];
        }

        return $rows;
    }

    /**
     * Сравнение по АБСОЛЮТНОМУ моменту, а не по строке: окно и курсор пишутся
     * в зоне приложения, поэтому `15:00+03:00` и `12:00Z` — одно и то же
     * время. Сравнение строк проверяло бы соглашение о записи, а не позицию.
     */
    private function assertSameInstant(string $expected, ?string $actual): void
    {
        self::assertNotNull($actual);
        self::assertSame(
            (new \DateTimeImmutable($expected))->getTimestamp(),
            (new \DateTimeImmutable($actual))->getTimestamp(),
        );
    }

    private function request(?string $cursorValue): PullRequest
    {
        return new PullRequest(
            companyId: Uuid::uuid7()->toString(),
            connectionRef: 'connection-1',
            shopRef: 'shop-main',
            resourceType: OzonResourceType::ORDERS_FBO,
            cursorValue: $cursorValue,
            windowFrom: null,
            windowTo: null,
            syncJobId: Uuid::uuid7()->toString(),
        );
    }
}
