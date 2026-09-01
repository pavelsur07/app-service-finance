<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Api\Ozon;

use App\Marketplace\Exception\OzonCatalogApiException;
use App\Marketplace\Exception\OzonCatalogRateLimitException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Обход каталога товаров Ozon Seller API.
 *
 * Пара эндпоинтов:
 * - `/v3/product/list` — идентификаторы ВСЕГО каталога, независимо от продаж.
 *   Именно он делает видимыми товары, по которым ещё не было финансовых операций.
 * - `/v3/product/info/list` — карточки: name, offer_id, sku, sources[], barcodes,
 *   created_at (дата создания товара на Ozon).
 *
 * Клиент отдаёт сырые декодированные payload'ы постранично, не склеивая их:
 * вызывающий обязан положить каждую страницу в raw-хранилище до нормализации.
 */
final readonly class OzonProductCatalogClient
{
    public const PAGE_LIMIT = 1000;
    public const INFO_CHUNK_SIZE = 1000;

    private const BASE_URL = 'https://api-seller.ozon.ru';
    private const PRODUCT_LIST_ENDPOINT = '/v3/product/list';
    private const PRODUCT_INFO_ENDPOINT = '/v3/product/info/list';
    private const TIMEOUT_SECONDS = 60;

    public function __construct(private HttpClientInterface $httpClient)
    {
    }

    /**
     * @return iterable<int, array<string, mixed>> сырые страницы /v3/product/list
     */
    public function iterateProductListPages(string $clientId, string $apiKey, int $limit = self::PAGE_LIMIT): iterable
    {
        $lastId = '';
        $seenCursors = [];

        do {
            $page = $this->post($clientId, $apiKey, self::PRODUCT_LIST_ENDPOINT, [
                'filter' => ['visibility' => 'ALL'],
                'last_id' => $lastId,
                'limit' => $limit,
            ]);

            yield $page;

            // Битый контракт не должен выглядеть успешной синхронизацией.
            // 200 с товарами, но без last_id тихо оборвал бы обход после первой
            // страницы, и неполный каталог отчитался бы успехом.
            $result = $page['result'] ?? null;
            if (!is_array($result) || !is_array($result['items'] ?? null)) {
                throw new OzonCatalogApiException('Ozon catalog /v3/product/list response has no result.items array.');
            }

            $nextLastId = $result['last_id'] ?? null;
            if (!is_string($nextLastId)) {
                throw new OzonCatalogApiException('Ozon catalog /v3/product/list response has no string last_id.');
            }

            // `total` — заявленный размер каталога. Без него обход нечем
            // сверить: оборванная пагинация выглядела бы полной выгрузкой.
            $total = $result['total'] ?? null;
            if (!is_int($total) || $total < 0) {
                throw new OzonCatalogApiException('Ozon catalog /v3/product/list response has no non-negative integer total.');
            }

            $items = $result['items'];
            $lastId = $nextLastId;

            // Курсор обязан продвигаться. Ozon, повторяющий тот же last_id с
            // непустыми items, крутил бы обход вечно: воркер занят, запросы
            // к API идут без остановки. Останавливаемся ошибкой, а не тихо:
            // это ненормальный ответ, и его должно быть видно.
            //
            // На ПУСТОЙ странице проверка не применяется: цикл и так завершится,
            // а повтор курсора там — штатный конец каталога, не зацикливание.
            if ([] !== $items && '' !== $lastId && isset($seenCursors[$lastId])) {
                throw new OzonCatalogApiException(sprintf('Ozon catalog pagination cursor did not advance after %d pages.', count($seenCursors)));
            }
            $seenCursors[$lastId] = true;

            // Пустая страница завершает обход даже при непустом last_id:
            // иначе Ozon, отдающий курсор без товаров, зациклил бы прогон.
        } while ([] !== $items && '' !== $lastId);
    }

    /**
     * @param list<int> $productIds не более self::INFO_CHUNK_SIZE за вызов
     *
     * @return array<string, mixed> сырой payload /v3/product/info/list
     */
    public function fetchProductInfo(string $clientId, string $apiKey, array $productIds): array
    {
        if ([] === $productIds) {
            return ['items' => []];
        }

        $payload = $this->post($clientId, $apiKey, self::PRODUCT_INFO_ENDPOINT, [
            'offer_id' => [],
            'product_id' => $productIds,
            'sku' => [],
        ]);

        if (!is_array($payload['items'] ?? null)) {
            throw new OzonCatalogApiException('Ozon catalog /v3/product/info/list response has no items array.');
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function post(string $clientId, string $apiKey, string $endpoint, array $body): array
    {
        try {
            $response = $this->httpClient->request('POST', self::BASE_URL.$endpoint, [
                'headers' => [
                    'Client-Id' => $clientId,
                    'Api-Key' => $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $body,
                'timeout' => self::TIMEOUT_SECONDS,
            ]);

            $statusCode = $response->getStatusCode();
            $payload = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new OzonCatalogApiException(sprintf('Ozon catalog API transport error for %s.', $endpoint), 0, $e);
        }

        // Тело ответа в сообщение не кладём целиком: это payload внешнего API.
        $message = sprintf('Ozon catalog API returned HTTP %d for %s.', $statusCode, $endpoint);

        if (429 === $statusCode) {
            throw new OzonCatalogRateLimitException($message.' Rate limit exceeded.');
        }
        if (200 !== $statusCode) {
            throw new OzonCatalogApiException($message);
        }

        try {
            $decoded = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new OzonCatalogApiException(sprintf('Ozon catalog API returned invalid JSON for %s.', $endpoint), 0, $e);
        }

        if (!is_array($decoded)) {
            throw new OzonCatalogApiException(sprintf('Ozon catalog API returned unexpected payload type for %s.', $endpoint));
        }

        return $decoded;
    }
}
