<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Infrastructure\Api\Ozon;

use App\Marketplace\Exception\OzonCatalogApiException;
use App\Marketplace\Exception\OzonCatalogRateLimitException;
use App\Marketplace\Infrastructure\Api\Ozon\OzonProductCatalogClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OzonProductCatalogClientTest extends TestCase
{
    public function testIteratesProductListPagesUntilLastIdIsEmpty(): void
    {
        $client = new OzonProductCatalogClient(new MockHttpClient([
            $this->productListPage([1, 2], lastId: 'cursor-1'),
            $this->productListPage([3], lastId: ''),
        ]));

        $pages = iterator_to_array($client->iterateProductListPages('client-id', 'api-key'));

        self::assertCount(2, $pages);
        self::assertSame(1, $pages[0]['result']['items'][0]['product_id']);
        self::assertSame(3, $pages[1]['result']['items'][0]['product_id']);
    }

    public function testSendsCursorOfPreviousPageAsLastId(): void
    {
        $requests = [];
        $client = new OzonProductCatalogClient(new MockHttpClient(
            function (string $method, string $url, array $options) use (&$requests): MockResponse {
                $requests[] = json_decode((string) $options['body'], true, 512, \JSON_THROW_ON_ERROR);

                return 1 === count($requests)
                    ? $this->productListPage([1], lastId: 'cursor-1')
                    : $this->productListPage([], lastId: '');
            },
        ));

        iterator_to_array($client->iterateProductListPages('client-id', 'api-key'));

        self::assertSame('', $requests[0]['last_id']);
        self::assertSame('cursor-1', $requests[1]['last_id']);
    }

    /**
     * Ozon может вернуть непустой last_id и пустой items. Без этой остановки
     * обход каталога стал бы бесконечным циклом внутри одного cron-прогона.
     */
    public function testStopsOnEmptyPageEvenWhenLastIdIsNotEmpty(): void
    {
        $client = new OzonProductCatalogClient(new MockHttpClient([
            $this->productListPage([1], lastId: 'cursor-1'),
            $this->productListPage([], lastId: 'cursor-2'),
        ]));

        self::assertCount(2, iterator_to_array($client->iterateProductListPages('client-id', 'api-key')));
    }

    /**
     * Пустая страница — не единственный способ зациклиться. Ozon, отдающий
     * непустые items с ТЕМ ЖЕ курсором, крутил бы обход вечно: воркер занят,
     * запросы к API идут без остановки. Обход обязан заметить непродвижение.
     */
    public function testStopsWhenCursorDoesNotAdvance(): void
    {
        $requests = 0;
        // Бесконечный источник одинаковых страниц: исчерпать мок нельзя,
        // поэтому зелёный тест доказывает именно защиту, а не конец очереди.
        $client = new OzonProductCatalogClient(new MockHttpClient(
            function () use (&$requests): MockResponse {
                if (++$requests > 5) {
                    self::fail('Обход не остановился на неподвижном курсоре.');
                }

                return $this->productListPage([$requests], lastId: 'stuck');
            },
        ));

        $this->expectException(OzonCatalogApiException::class);
        iterator_to_array($client->iterateProductListPages('client-id', 'api-key'));
    }

    /**
     * 200 с товарами, но без last_id тихо оборвал бы обход после первой
     * страницы: каталог остался бы неполным, а прогон отчитался бы успехом.
     * Проверка, утверждающая шире, чем то, что она видит, — ложный зелёный.
     */
    public function testProductListWithoutLastIdIsRejected(): void
    {
        $client = new OzonProductCatalogClient(new MockHttpClient(
            new MockResponse('{"result":{"items":[{"product_id":1}],"total":1}}', ['http_code' => 200]),
        ));

        $this->expectException(OzonCatalogApiException::class);
        iterator_to_array($client->iterateProductListPages('client-id', 'api-key'));
    }

    public function testProductListWithoutResultItemsIsRejected(): void
    {
        $client = new OzonProductCatalogClient(new MockHttpClient(
            new MockResponse('{"result":{"last_id":""}}', ['http_code' => 200]),
        ));

        $this->expectException(OzonCatalogApiException::class);
        iterator_to_array($client->iterateProductListPages('client-id', 'api-key'));
    }

    public function testProductInfoWithoutItemsIsRejected(): void
    {
        $client = new OzonProductCatalogClient(new MockHttpClient(
            new MockResponse('{"result":"unexpected"}', ['http_code' => 200]),
        ));

        $this->expectException(OzonCatalogApiException::class);
        $client->fetchProductInfo('client-id', 'api-key', [1]);
    }

    /**
     * Защита от неподвижного курсора не должна срабатывать на штатном
     * завершении обхода: пустая страница уже гарантирует выход, и повтор
     * курсора на ней — не зацикливание, а конец каталога.
     */
    public function testEmptyFinalPageWithRepeatedCursorEndsTheWalkWithoutError(): void
    {
        $client = new OzonProductCatalogClient(new MockHttpClient([
            $this->productListPage([1], lastId: 'cursor-1'),
            $this->productListPage([], lastId: 'cursor-1'),
        ]));

        self::assertCount(2, iterator_to_array($client->iterateProductListPages('client-id', 'api-key')));
    }

    public function testProductListWithoutTotalIsRejected(): void
    {
        $client = new OzonProductCatalogClient(new MockHttpClient(
            new MockResponse('{"result":{"items":[{"product_id":1}],"last_id":""}}', ['http_code' => 200]),
        ));

        $this->expectException(OzonCatalogApiException::class);
        iterator_to_array($client->iterateProductListPages('client-id', 'api-key'));
    }

    public function testSendsCredentialsAsHeaders(): void
    {
        $seen = [];
        $client = new OzonProductCatalogClient(new MockHttpClient(
            function (string $method, string $url, array $options) use (&$seen): MockResponse {
                $seen = $options['headers'];

                return new MockResponse('{"items":[]}', ['http_code' => 200]);
            },
        ));

        $client->fetchProductInfo('the-client-id', 'the-api-key', [1]);

        self::assertContains('Client-Id: the-client-id', $seen);
        self::assertContains('Api-Key: the-api-key', $seen);
    }

    public function testFetchProductInfoSendsRequestedProductIds(): void
    {
        $body = [];
        $client = new OzonProductCatalogClient(new MockHttpClient(
            function (string $method, string $url, array $options) use (&$body): MockResponse {
                $body = json_decode((string) $options['body'], true, 512, \JSON_THROW_ON_ERROR);

                return new MockResponse('{"items":[]}', ['http_code' => 200]);
            },
        ));

        $client->fetchProductInfo('client-id', 'api-key', [10, 20]);

        self::assertSame([10, 20], $body['product_id']);
    }

    public function testFetchProductInfoWithoutIdsMakesNoRequest(): void
    {
        $client = new OzonProductCatalogClient(new MockHttpClient([]));

        self::assertSame(['items' => []], $client->fetchProductInfo('client-id', 'api-key', []));
    }

    public function testRateLimitIsDistinguishableFromOtherApiErrors(): void
    {
        $client = new OzonProductCatalogClient(new MockHttpClient(
            new MockResponse('{"message":"too many"}', ['http_code' => 429]),
        ));

        $this->expectException(OzonCatalogRateLimitException::class);
        $client->fetchProductInfo('client-id', 'api-key', [1]);
    }

    public function testUnauthorizedRaisesApiException(): void
    {
        $client = new OzonProductCatalogClient(new MockHttpClient(
            new MockResponse('{"message":"nope"}', ['http_code' => 401]),
        ));

        $this->expectException(OzonCatalogApiException::class);
        $client->fetchProductInfo('client-id', 'api-key', [1]);
    }

    public function testInvalidJsonRaisesApiException(): void
    {
        $client = new OzonProductCatalogClient(new MockHttpClient(
            new MockResponse('{not json', ['http_code' => 200]),
        ));

        $this->expectException(OzonCatalogApiException::class);
        $client->fetchProductInfo('client-id', 'api-key', [1]);
    }

    public function testServerErrorRaisesApiException(): void
    {
        $client = new OzonProductCatalogClient(new MockHttpClient(
            new MockResponse('{"message":"boom"}', ['http_code' => 500]),
        ));

        $this->expectException(OzonCatalogApiException::class);
        $client->fetchProductInfo('client-id', 'api-key', [1]);
    }

    /**
     * @param list<int> $productIds
     */
    private function productListPage(array $productIds, string $lastId): MockResponse
    {
        return new MockResponse(json_encode([
            'result' => [
                'items' => array_map(
                    static fn (int $id): array => ['product_id' => $id, 'offer_id' => 'A-'.$id, 'sku' => $id * 10],
                    $productIds,
                ),
                'total' => count($productIds),
                'last_id' => $lastId,
            ],
        ], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }
}
