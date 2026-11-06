<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Api\Wildberries;

use App\Inventory\Exception\WbInventoryApiException;
use App\Inventory\Exception\WbInventoryRateLimitException;
use App\Inventory\Exception\WbInventoryTemporaryApiException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class WbInventoryClient
{
    public const DEFAULT_LIMIT = 50000;
    /** Остатки на складах WB (FBW), seller-analytics-api. */
    public const ENDPOINT = '/api/analytics/v1/stocks-report/wb-warehouses';
    /** Склады продавца (FBS), marketplace-api. */
    public const SELLER_WAREHOUSES_ENDPOINT = '/api/v3/warehouses';
    /** Остатки склада продавца по баркодам: SELLER_STOCKS_ENDPOINT.'/{warehouseId}'. */
    public const SELLER_STOCKS_ENDPOINT = '/api/v3/stocks';
    /** Максимум баркодов в одном запросе остатков склада продавца. */
    public const SELLER_STOCKS_CHUNK = 1000;

    private const BASE_URL = 'https://seller-analytics-api.wildberries.ru';
    private const MARKETPLACE_BASE_URL = 'https://marketplace-api.wildberries.ru';
    private const MAX_LIMIT = 250000;

    public function __construct(private HttpClientInterface $httpClient)
    {
    }

    public function fetchStocks(string $apiKey, int $limit = self::DEFAULT_LIMIT, int $offset = 0): WbInventoryResponse
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException(sprintf('limit must be between 1 and %d.', self::MAX_LIMIT));
        }
        if ($offset < 0) {
            throw new \InvalidArgumentException('offset must be greater than or equal to 0.');
        }

        /** @var array<string, mixed> $decoded */
        $decoded = $this->requestJson($apiKey, 'POST', self::BASE_URL, self::ENDPOINT, [
            'nmIds' => [],
            'chrtIds' => [],
            'limit' => $limit,
            'offset' => $offset,
        ]);

        if (!isset($decoded['data']) || !is_array($decoded['data']) || !isset($decoded['data']['items']) || !is_array($decoded['data']['items']) || !array_is_list($decoded['data']['items'])) {
            throw new WbInventoryApiException('WB Inventory API returned unexpected response structure.');
        }

        $items = [];
        foreach ($decoded['data']['items'] as $item) {
            if (!is_array($item)) {
                throw new WbInventoryApiException('WB Inventory API returned a non-object inventory item.');
            }
            $items[] = $item;
        }

        return new WbInventoryResponse(
            raw: $decoded,
            items: $items,
            hasNextPage: count($items) === $limit,
        );
    }

    /**
     * Склады продавца. Элементы без числового id пропускаются, пустое имя заменяется техническим.
     *
     * @return list<array{id: int, name: string}>
     */
    public function fetchSellerWarehouses(string $apiKey): array
    {
        $decoded = $this->requestJson($apiKey, 'GET', self::MARKETPLACE_BASE_URL, self::SELLER_WAREHOUSES_ENDPOINT);
        if (!array_is_list($decoded)) {
            throw new WbInventoryApiException('WB Inventory API returned unexpected seller warehouses structure.');
        }

        $warehouses = [];
        foreach ($decoded as $warehouse) {
            if (!is_array($warehouse) || !is_int($warehouse['id'] ?? null)) {
                continue;
            }
            $name = trim((string) ($warehouse['name'] ?? ''));
            $warehouses[] = ['id' => $warehouse['id'], 'name' => '' !== $name ? $name : 'WB seller warehouse '.$warehouse['id']];
        }

        return $warehouses;
    }

    /**
     * Остатки склада продавца по баркодам — сырой ответ (ключ `stocks`), разбор у нормализатора.
     *
     * @param list<string> $barcodes
     *
     * @return array<string, mixed>
     */
    public function fetchSellerWarehouseStocks(string $apiKey, int $warehouseId, array $barcodes): array
    {
        if ([] === $barcodes || count($barcodes) > self::SELLER_STOCKS_CHUNK) {
            throw new \InvalidArgumentException(sprintf('barcodes must contain between 1 and %d items.', self::SELLER_STOCKS_CHUNK));
        }

        /** @var array<string, mixed> $decoded */
        $decoded = $this->requestJson($apiKey, 'POST', self::MARKETPLACE_BASE_URL, self::SELLER_STOCKS_ENDPOINT.'/'.$warehouseId, ['skus' => $barcodes]);

        return $decoded;
    }

    /**
     * @param array<string, mixed>|null $json
     *
     * @return array<mixed> декодированный JSON верхнего уровня: объект или список
     */
    private function requestJson(string $apiKey, string $method, string $baseUrl, string $endpoint, ?array $json = null): array
    {
        if ('' === trim($apiKey)) {
            throw new \InvalidArgumentException('apiKey must not be empty.');
        }

        $options = [
            'headers' => [
                'Authorization' => $apiKey,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 120,
        ];
        if (null !== $json) {
            $options['json'] = $json;
        }

        try {
            $response = $this->httpClient->request($method, $baseUrl.$endpoint, $options);

            $statusCode = $response->getStatusCode();
            $headers = $response->getHeaders(false);
        } catch (TransportExceptionInterface $e) {
            throw new WbInventoryTemporaryApiException('WB Inventory API transport error.', previous: $e);
        }

        $message = sprintf('WB Inventory API returned HTTP %d for %s.', $statusCode, $endpoint);

        if (429 === $statusCode) {
            throw new WbInventoryRateLimitException($message, $this->retryAfterSeconds($headers));
        }
        if (in_array($statusCode, [400, 401, 402, 403], true)) {
            throw new WbInventoryApiException($message);
        }
        if ($statusCode >= 500) {
            throw new WbInventoryTemporaryApiException($message);
        }
        if (200 !== $statusCode) {
            throw new WbInventoryApiException($message);
        }

        try {
            $payload = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new WbInventoryTemporaryApiException('WB Inventory API transport error.', previous: $e);
        }

        try {
            $decoded = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new WbInventoryApiException('WB Inventory API returned invalid JSON.', previous: $e);
        }

        if (!is_array($decoded)) {
            throw new WbInventoryApiException('WB Inventory API returned unexpected response structure.');
        }

        return $decoded;
    }

    /** @param array<string, list<string>> $headers */
    private function retryAfterSeconds(array $headers): ?int
    {
        $value = $headers['retry-after'][0] ?? $headers['x-ratelimit-retry'][0] ?? null;
        if (!is_string($value) || !ctype_digit($value)) {
            return null;
        }

        return max(1, (int) $value);
    }
}
