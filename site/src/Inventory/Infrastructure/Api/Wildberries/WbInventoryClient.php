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
    public const MIN_REQUEST_INTERVAL_SECONDS = 20;
    public const ENDPOINT = '/api/analytics/v1/stocks-report/wb-warehouses';

    private const BASE_URL = 'https://seller-analytics-api.wildberries.ru';
    private const MAX_LIMIT = 250000;

    public function __construct(private HttpClientInterface $httpClient)
    {
    }

    public function fetchStocks(string $apiKey, int $limit = self::DEFAULT_LIMIT, int $offset = 0): WbInventoryResponse
    {
        if ('' === trim($apiKey)) {
            throw new \InvalidArgumentException('apiKey must not be empty.');
        }
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException(sprintf('limit must be between 1 and %d.', self::MAX_LIMIT));
        }
        if ($offset < 0) {
            throw new \InvalidArgumentException('offset must be greater than or equal to 0.');
        }

        try {
            $response = $this->httpClient->request('POST', self::BASE_URL.self::ENDPOINT, [
                'headers' => [
                    'Authorization' => $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'nmIds' => [],
                    'chrtIds' => [],
                    'limit' => $limit,
                    'offset' => $offset,
                ],
                'timeout' => 120,
            ]);

            $statusCode = $response->getStatusCode();
            $headers = $response->getHeaders(false);
        } catch (TransportExceptionInterface $e) {
            throw new WbInventoryTemporaryApiException('WB Inventory API transport error.', previous: $e);
        }

        $message = sprintf('WB Inventory API returned HTTP %d for %s.', $statusCode, self::ENDPOINT);

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

        if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data']) || !isset($decoded['data']['items']) || !is_array($decoded['data']['items']) || !array_is_list($decoded['data']['items'])) {
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
