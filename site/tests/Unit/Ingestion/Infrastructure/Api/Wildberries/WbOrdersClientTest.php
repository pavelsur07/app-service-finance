<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Infrastructure\Api\Wildberries;

use App\Ingestion\Exception\ConnectorAuthException;
use App\Ingestion\Exception\ConnectorRateLimitedException;
use App\Ingestion\Exception\ConnectorTransientException;
use App\Ingestion\Exception\MalformedConnectorResponseException;
use App\Ingestion\Infrastructure\Api\Wildberries\WbCredentialProviderInterface;
use App\Ingestion\Infrastructure\Api\Wildberries\WbOrdersClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WbOrdersClientTest extends TestCase
{
    private const COMPANY_ID = '0192f0c2-0000-7000-8000-000000000001';
    private const CONNECTION_REF = '0192f0c2-0000-7000-8000-000000000002';

    public function testMarketplaceOrdersSendsUnixDateFromAndReadsNextCursor(): void
    {
        $captured = null;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('{"next":777,"orders":[{"id":1,"rid":"r-1"}]}', ['http_code' => 200]);
        });

        $page = $this->client($http)->fetchMarketplaceOrders(
            self::COMPANY_ID,
            self::CONNECTION_REF,
            new \DateTimeImmutable('2026-09-01T10:00:00+00:00'),
            1000,
            0,
        );

        self::assertNotNull($captured);
        self::assertSame('GET', $captured['method']);
        // dateFrom у marketplace — unix-время, часовых поясов у него нет.
        self::assertStringContainsString('dateFrom='.(new \DateTimeImmutable('2026-09-01T10:00:00+00:00'))->getTimestamp(), $captured['url']);
        self::assertSame([['id' => 1, 'rid' => 'r-1']], $page->rows);
        self::assertSame(777, $page->nextToken);
        self::assertTrue($page->hasMore);
    }

    /**
     * Признак «есть ещё» у WB — непустая страница: документированного флага у
     * эндпоинта нет, поэтому пустой ответ и есть конец обхода.
     */
    public function testEmptyMarketplacePageEndsPagination(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('{"next":0,"orders":[]}', ['http_code' => 200])));

        $page = $client->fetchMarketplaceOrders(
            self::COMPANY_ID,
            self::CONNECTION_REF,
            new \DateTimeImmutable('2026-09-01T10:00:00+00:00'),
            1000,
            0,
        );

        self::assertSame([], $page->rows);
        self::assertFalse($page->hasMore);
    }

    /**
     * statistics-api работает в МОСКОВСКОМ времени: в выгрузке один и тот же
     * заказ имеет createdAt 19:18:04Z в marketplace и date 22:18:04 без зоны в
     * statistics — ровно +3. Отправленный UTC-момент сместил бы окно на три
     * часа.
     */
    public function testStatisticsDateFromIsSentInMoscowTime(): void
    {
        $captured = null;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $url;

            return new MockResponse('[]', ['http_code' => 200]);
        });

        $this->client($http)->fetchStatisticsOrders(
            self::COMPANY_ID,
            self::CONNECTION_REF,
            new \DateTimeImmutable('2026-09-01T10:00:00+00:00'),
        );

        self::assertStringContainsString('dateFrom=2026-09-01T13:00:00', (string) $captured);
        self::assertStringContainsString('flag=0', (string) $captured);
    }

    public function testStatisticsReturnsFlatListWithoutPagination(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('[{"srid":"s-1"},{"srid":"s-2"}]', ['http_code' => 200])));

        $page = $client->fetchStatisticsOrders(
            self::COMPANY_ID,
            self::CONNECTION_REF,
            new \DateTimeImmutable('2026-09-01T10:00:00+00:00'),
        );

        self::assertCount(2, $page->rows);
        self::assertFalse($page->hasMore);
    }

    public function testStatusesAreIndexedByOrderId(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse(
            '{"orders":[{"id":5,"supplierStatus":"new","wbStatus":"waiting","isCancellable":true},{"id":7,"supplierStatus":"complete","wbStatus":"sorted","isCancellable":false}]}',
            ['http_code' => 200],
        )));

        $page = $client->fetchMarketplaceStatuses(self::COMPANY_ID, self::CONNECTION_REF, [5, 7]);

        self::assertSame(['new', 'complete'], [$page->statuses[5]['supplierStatus'], $page->statuses[7]['supplierStatus']]);
        self::assertSame(0, $page->rejectedRows);
    }

    /**
     * Пустой список номеров не должен приводить к пустому POST в WB.
     */
    public function testEmptyStatusRequestMakesNoCall(): void
    {
        $calls = 0;
        $http = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('{"orders":[]}', ['http_code' => 200]);
        });

        self::assertSame([], $this->client($http)->fetchMarketplaceStatuses(self::COMPANY_ID, self::CONNECTION_REF, [])->statuses);
        self::assertSame(0, $calls);
    }

    /**
     * 204 — легальное «изменений нет», а не ошибка.
     */
    public function testNoContentIsAnEmptyPage(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('', ['http_code' => 204])));

        $page = $client->fetchStatisticsOrders(
            self::COMPANY_ID,
            self::CONNECTION_REF,
            new \DateTimeImmutable('2026-09-01T10:00:00+00:00'),
        );

        self::assertSame([], $page->rows);
    }

    public function testRateLimitCarriesRetryAfter(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('{}', [
            'http_code' => 429,
            'response_headers' => ['retry-after' => '30'],
        ])));

        try {
            $client->fetchStatisticsOrders(self::COMPANY_ID, self::CONNECTION_REF, new \DateTimeImmutable('2026-09-01T10:00:00+00:00'));
            self::fail('Rate limit must throw.');
        } catch (ConnectorRateLimitedException $exception) {
            self::assertSame(30, $exception->retryAfterSeconds());
        }
    }

    public function testRateLimitWithoutHeaderUsesDefault(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('{}', ['http_code' => 429])));

        try {
            $client->fetchStatisticsOrders(self::COMPANY_ID, self::CONNECTION_REF, new \DateTimeImmutable('2026-09-01T10:00:00+00:00'));
            self::fail('Rate limit must throw.');
        } catch (ConnectorRateLimitedException $exception) {
            self::assertSame(70, $exception->retryAfterSeconds());
        }
    }

    #[DataProvider('transientStatusProvider')]
    public function testTimeoutAndServerStatusesAreTransient(int $statusCode): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('{}', ['http_code' => $statusCode])));

        $this->expectException(ConnectorTransientException::class);
        $client->fetchStatisticsOrders(self::COMPANY_ID, self::CONNECTION_REF, new \DateTimeImmutable('2026-09-01T10:00:00+00:00'));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function transientStatusProvider(): iterable
    {
        yield 'request timeout' => [408];
        yield 'too early' => [425];
        yield 'bad gateway' => [502];
        yield 'gateway timeout' => [504];
    }

    #[DataProvider('authStatusProvider')]
    public function testAuthStatusesBecomeAuthException(int $statusCode): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('{}', ['http_code' => $statusCode])));

        $this->expectException(ConnectorAuthException::class);
        $client->fetchStatisticsOrders(self::COMPANY_ID, self::CONNECTION_REF, new \DateTimeImmutable('2026-09-01T10:00:00+00:00'));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function authStatusProvider(): iterable
    {
        yield 'unauthorized' => [401];
        yield 'forbidden' => [403];
    }

    /**
     * json_decode(..., true) отдаёт объект тем же массивом: без проверки на
     * список ассоциативный контейнер прошёл бы за страницу, а вложенный
     * список — за строку заказа. Счётчик строк при этом решает, продолжать ли
     * пагинацию.
     */
    #[DataProvider('malformedShapeProvider')]
    public function testMalformedShapesAreRejected(string $payload): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse($payload, ['http_code' => 200])));

        $this->expectException(MalformedConnectorResponseException::class);
        $client->fetchMarketplaceOrders(
            self::COMPANY_ID,
            self::CONNECTION_REF,
            new \DateTimeImmutable('2026-09-01T10:00:00+00:00'),
            1000,
            0,
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedShapeProvider(): iterable
    {
        yield 'не JSON' => ['не json'];
        yield 'orders — объект' => ['{"next":0,"orders":{"a":{"id":1}}}'];
        yield 'orders — список списков' => ['{"next":0,"orders":[["broken"]]}'];
        yield 'orders — пустой объект в строке' => ['{"next":0,"orders":[{}]}'];
        yield 'next не число' => ['{"next":"777","orders":[]}'];
        yield 'orders отсутствует' => ['{"next":0}'];
        // json_decode(..., true) отдаёт `{}` тем же пустым массивом, что и
        // `[]`. Для statistics это не безобидно: пустой ответ двигает курсор,
        // и испорченный означал бы окончательный пропуск окна.
        yield 'orders — пустой объект' => ['{"next":0,"orders":{}}'];
    }

    /**
     * Пустой объект вместо списка у statistics особенно опасен: пустой ответ
     * двигает курсор к времени запроса, поэтому испорченный ответ означал бы
     * окончательный пропуск окна изменений.
     */
    public function testEmptyObjectInsteadOfStatisticsListIsMalformed(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('{}', ['http_code' => 200])));

        $this->expectException(MalformedConnectorResponseException::class);
        $client->fetchStatisticsOrders(self::COMPANY_ID, self::CONNECTION_REF, new \DateTimeImmutable('2026-09-01T10:00:00+00:00'));
    }

    public function testEmptyListIsStillAValidStatisticsAnswer(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('[]', ['http_code' => 200])));

        $page = $client->fetchStatisticsOrders(self::COMPANY_ID, self::CONNECTION_REF, new \DateTimeImmutable('2026-09-01T10:00:00+00:00'));

        self::assertSame([], $page->rows);
    }

    public function testStatusRowWithoutIntegerIdIsRejected(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('{"orders":[{"supplierStatus":"new"}]}', ['http_code' => 200])));

        $page = $client->fetchMarketplaceStatuses(self::COMPANY_ID, self::CONNECTION_REF, [5]);

        self::assertSame([], $page->statuses);
        self::assertSame(1, $page->rejectedRows);
    }

    /**
     * Повреждённая СТРОКА не роняет ответ: он, как правило, покрывает всё
     * подключение, и исключение на первой кривой строке навсегда блокировало
     * бы обновление всех остальных корректных заказов.
     */
    public function testValidRowsSurviveAMalformedNeighbour(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse(
            '{"orders":[{"id":5,"supplierStatus":"","wbStatus":"waiting","isCancellable":true},{"id":7,"supplierStatus":"complete","wbStatus":"sorted","isCancellable":false}]}',
            ['http_code' => 200],
        )));

        $page = $client->fetchMarketplaceStatuses(self::COMPANY_ID, self::CONNECTION_REF, [5, 7]);

        self::assertSame([7], array_keys($page->statuses));
        self::assertSame(1, $page->rejectedRows);
        self::assertNotNull($page->evidence, 'Отбракованная строка обязана дойти до аудита.');
    }

    /**
     * Ось длиннее предела: склейка осей уходит в `raw_status`, а это
     * VARCHAR(255). Пропущенная сюда строка уронила бы финальный flush и
     * откатила аудит, события и отметки попыток всего подключения.
     */
    public function testOverlongStatusAxisIsRejected(): void
    {
        $payload = sprintf(
            '{"orders":[{"id":5,"supplierStatus":"%s","wbStatus":"sorted","isCancellable":false}]}',
            str_repeat('x', 101),
        );

        $page = $this->client(new MockHttpClient(new MockResponse($payload, ['http_code' => 200])))
            ->fetchMarketplaceStatuses(self::COMPANY_ID, self::CONNECTION_REF, [5]);

        self::assertSame([], $page->statuses);
        self::assertSame(1, $page->rejectedRows);
    }

    /**
     * Чужой номер означает, что строка относится не к нашему запросу: принять
     * её значило бы записать статус постороннего заказа.
     */
    public function testRowForAnOrderThatWasNotRequestedIsRejected(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse(
            '{"orders":[{"id":9,"supplierStatus":"new","wbStatus":"waiting","isCancellable":true}]}',
            ['http_code' => 200],
        )));

        $page = $client->fetchMarketplaceStatuses(self::COMPANY_ID, self::CONNECTION_REF, [5]);

        self::assertSame([], $page->statuses);
        self::assertSame(1, $page->rejectedRows);
    }

    #[DataProvider('invalidArgumentProvider')]
    public function testInvalidArgumentsAreRejectedBeforeTheCall(int $limit, int $next): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('{"next":0,"orders":[]}', ['http_code' => 200])));

        $this->expectException(\InvalidArgumentException::class);
        $client->fetchMarketplaceOrders(
            self::COMPANY_ID,
            self::CONNECTION_REF,
            new \DateTimeImmutable('2026-09-01T10:00:00+00:00'),
            $limit,
            $next,
        );
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function invalidArgumentProvider(): iterable
    {
        yield 'лимит ноль' => [0, 0];
        yield 'лимит выше предела' => [WbOrdersClient::MARKETPLACE_PAGE_LIMIT + 1, 0];
        yield 'отрицательный курсор' => [1000, -1];
    }

    /**
     * Обе оси статуса обязательны. Строка без них становилась НАБЛЮДЕНИЕМ с
     * пустыми осями: заказ получал UNKNOWN, ложное событие журнала и
     * статусную отметку, закрывающую дорогу более старому, но настоящему
     * статусу. Повреждённый ответ обязан вести к повтору, а не к записи
     * выдуманного состояния.
     */
    #[DataProvider('brokenStatusRowProvider')]
    public function testStatusRowWithoutBothAxesIsRejected(string $payload): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse($payload, ['http_code' => 200])));

        $page = $client->fetchMarketplaceStatuses(self::COMPANY_ID, self::CONNECTION_REF, [5]);

        self::assertSame([], $page->statuses);
        self::assertSame(1, $page->rejectedRows);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenStatusRowProvider(): iterable
    {
        yield 'только id' => ['{"orders":[{"id":5}]}'];
        yield 'нет wbStatus' => ['{"orders":[{"id":5,"supplierStatus":"new"}]}'];
        yield 'нет supplierStatus' => ['{"orders":[{"id":5,"wbStatus":"waiting"}]}'];
        yield 'ось не строка' => ['{"orders":[{"id":5,"supplierStatus":"new","wbStatus":7}]}'];
        yield 'isCancellable не bool' => ['{"orders":[{"id":5,"supplierStatus":"new","wbStatus":"waiting","isCancellable":"да"}]}'];
        yield 'isCancellable отсутствует' => ['{"orders":[{"id":5,"supplierStatus":"new","wbStatus":"waiting"}]}'];
    }

    private function client(MockHttpClient $http): WbOrdersClient
    {
        return new WbOrdersClient($http, $this->credentialProvider(), new NullLogger());
    }

    private function credentialProvider(): WbCredentialProviderInterface
    {
        return new class implements WbCredentialProviderInterface {
            public function read(string $companyId, string $connectionRef): array
            {
                return ['api_key' => 'test-api-key'];
            }
        };
    }
}
