<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Api\Ozon;

use App\Inventory\Exception\OzonInventoryApiException;
use App\Inventory\Exception\OzonInventoryRateLimitException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class OzonInventoryClient
{
    private const BASE_URL = 'https://api-seller.ozon.ru';
    private const STOCKS_ENDPOINT = '/v4/product/info/stocks';
    private const MIN_LIMIT = 1;
    private const MAX_LIMIT = 1000;

    public function __construct(private HttpClientInterface $httpClient)
    {
    }

    public function fetchStocks(string $clientId, string $apiKey, int $limit = self::MAX_LIMIT, ?string $lastId = null): OzonInventoryResponse
    {
        $this->assertInput($clientId, $apiKey, $limit);

        $body = [
            'filter' => ['visibility' => 'ALL'],
            'limit' => $limit,
        ];
        if (null !== $lastId && '' !== $lastId) {
            $body['last_id'] = $lastId;
        }

        try {
            $response = $this->httpClient->request('POST', self::BASE_URL.self::STOCKS_ENDPOINT, [
                'headers' => ['Client-Id' => $clientId, 'Api-Key' => $apiKey, 'Content-Type' => 'application/json'],
                'json' => $body,
                'timeout' => 30,
            ]);
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException('Ozon Inventory API transport error.', previous: $e);
        }

        $statusCode = $response->getStatusCode();
        $payload = $response->getContent(false);
        $payloadFragment = mb_substr(trim($payload), 0, 1000);
        $message = sprintf(
            'Ozon Inventory API returned HTTP %d for %s: %s',
            $statusCode,
            self::STOCKS_ENDPOINT,
            $payloadFragment !== '' ? $payloadFragment : '[empty response body]',
        );

        if (429 === $statusCode) {
            throw new OzonInventoryRateLimitException($message.' Rate limit exceeded, retry later.');
        }
        if (401 === $statusCode || 403 === $statusCode) {
            throw new OzonInventoryApiException($message.' Authentication/authorization failed.');
        }
        if (400 === $statusCode) {
            throw new OzonInventoryApiException($message.' Invalid request payload or parameters.');
        }
        if ($statusCode >= 400 && $statusCode < 500) {
            throw new OzonInventoryApiException($message.' Client-side API error.');
        }
        if ($statusCode >= 500) {
            throw new \RuntimeException($message.' Ozon server-side error.');
        }

        try {
            $decoded = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new OzonInventoryApiException('Ozon Inventory API returned invalid JSON.', previous: $e);
        }

        if (!is_array($decoded)) {
            throw new OzonInventoryApiException('Ozon Inventory API returned unexpected payload type.');
        }

        $nextLastId = null;
        if (isset($decoded['result']) && is_array($decoded['result']) && array_key_exists('last_id', $decoded['result'])) {
            $v = $decoded['result']['last_id'];
            $nextLastId = is_string($v) && '' !== $v ? $v : null;
        }

        return new OzonInventoryResponse($decoded, $nextLastId);
    }

    private function assertInput(string $clientId, string $apiKey, int $limit): void
    {
        if ('' === trim($clientId)) {
            throw new \InvalidArgumentException('clientId must not be empty.');
        }

        if ('' === trim($apiKey)) {
            throw new \InvalidArgumentException('apiKey must not be empty.');
        }

        if ($limit < self::MIN_LIMIT || $limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException(sprintf('limit must be between %d and %d.', self::MIN_LIMIT, self::MAX_LIMIT));
        }
    }
}
