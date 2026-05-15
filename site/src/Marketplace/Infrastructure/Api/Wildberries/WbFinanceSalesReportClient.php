<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Api\Wildberries;

use App\Marketplace\Exception\MarketplaceAuthException;
use App\Marketplace\Exception\MarketplaceInvalidApiResponseException;
use App\Marketplace\Exception\MarketplaceRateLimitException;
use App\Marketplace\Exception\MarketplaceTemporaryApiException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class WbFinanceSalesReportClient
{
    private const BASE_URL = 'https://finance-api.wildberries.ru';
    private const PAGE_SIZE = 100000;
    private const WB_HEADER_RETRY = 'x-ratelimit-retry';
    private const WB_HEADER_RESET = 'x-ratelimit-reset';

    public function __construct(private HttpClientInterface $httpClient)
    {
    }

    /** @return list<array<string,mixed>> */
    public function fetchDetailed(string $apiKey, string $dateFrom, string $dateTo): array
    {
        $rows = [];
        $rrdId = 0;

        while (true) {
            try {
                $response = $this->httpClient->request('POST', self::BASE_URL.'/api/finance/v1/sales-reports/detailed', [
                    'headers' => ['Authorization' => $apiKey],
                    'json' => [
                        'dateFrom' => $dateFrom,
                        'dateTo' => $dateTo,
                        'limit' => self::PAGE_SIZE,
                        'rrdId' => $rrdId,
                        'period' => 'daily',
                    ],
                    'timeout' => 120,
                ]);
                $statusCode = $response->getStatusCode();
                $headers = $response->getHeaders(false);
                $body = $response->getContent(false);
            } catch (TransportExceptionInterface $e) {
                throw new MarketplaceTemporaryApiException('WB API transport error.', 0, '', $dateFrom, $dateTo, $e);
            }

            $excerpt = $this->createSafeExcerpt($body);

            if (204 === $statusCode) {
                return $rows;
            }
            if (429 === $statusCode) {
                throw new MarketplaceRateLimitException($statusCode, $excerpt, $dateFrom, $dateTo, $this->extractRetryAfter($headers));
            }
            if (401 === $statusCode || 403 === $statusCode) {
                throw new MarketplaceAuthException('WB API authentication failed.', $statusCode, $excerpt, $dateFrom, $dateTo);
            }
            if ($statusCode >= 500 && $statusCode <= 599) {
                throw new MarketplaceTemporaryApiException('WB API temporary error.', $statusCode, $excerpt, $dateFrom, $dateTo);
            }
            if (200 !== $statusCode) {
                throw new MarketplaceTemporaryApiException('WB API unexpected status.', $statusCode, $excerpt, $dateFrom, $dateTo);
            }
            if ('' === trim($body)) {
                throw new MarketplaceInvalidApiResponseException('WB API JSON must be a list.', $statusCode, $excerpt, $dateFrom, $dateTo);
            }

            try {
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new MarketplaceInvalidApiResponseException('WB API returned invalid JSON.', $statusCode, $excerpt, $dateFrom, $dateTo, $e);
            }

            if (!is_array($decoded) || !array_is_list($decoded)) {
                throw new MarketplaceInvalidApiResponseException('WB API JSON must be a list.', $statusCode, $excerpt, $dateFrom, $dateTo);
            }

            foreach ($decoded as $row) {
                if (!is_array($row)) {
                    throw new MarketplaceInvalidApiResponseException('WB API JSON list items must be objects.', $statusCode, $excerpt, $dateFrom, $dateTo);
                }
            }

            if ([] === $decoded) {
                return $rows;
            }

            $rows = [...$rows, ...$decoded];
            $last = end($decoded);
            $newRrdId = is_array($last) ? (int) ($last['rrdId'] ?? 0) : 0;
            if ($newRrdId <= $rrdId) {
                throw new MarketplaceInvalidApiResponseException('WB API pagination rrdId must grow monotonically.', $statusCode, $excerpt, $dateFrom, $dateTo);
            }

            $rrdId = $newRrdId;
        }
    }



    public function probeAccess(string $apiKey): bool
    {
        $today = (new \DateTimeImmutable())->format('Y-m-d');

        try {
            $response = $this->httpClient->request('POST', self::BASE_URL.'/api/finance/v1/sales-reports/detailed', [
                'headers' => ['Authorization' => $apiKey],
                'json' => [
                    'dateFrom' => $today,
                    'dateTo' => $today,
                    'limit' => 1,
                    'rrdId' => 0,
                    'period' => 'daily',
                ],
                'timeout' => 120,
            ]);
            $statusCode = $response->getStatusCode();
        } catch (TransportExceptionInterface) {
            return false;
        }

        if (200 === $statusCode || 204 === $statusCode) {
            return true;
        }

        return false;
    }

    public function hasAnyData(string $apiKey, string $dateFrom, string $dateTo): bool
    {
        try {
            $response = $this->httpClient->request('POST', self::BASE_URL.'/api/finance/v1/sales-reports/detailed', [
                'headers' => ['Authorization' => $apiKey],
                'json' => [
                    'dateFrom' => $dateFrom,
                    'dateTo' => $dateTo,
                    'limit' => 1,
                    'rrdId' => 0,
                    'period' => 'daily',
                ],
                'timeout' => 120,
            ]);
            $statusCode = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new MarketplaceTemporaryApiException('WB API transport error.', 0, '', $dateFrom, $dateTo, $e);
        }

        $excerpt = $this->createSafeExcerpt($body);
        if (204 === $statusCode) {
            return false;
        }
        if (429 === $statusCode) {
            throw new MarketplaceRateLimitException($statusCode, $excerpt, $dateFrom, $dateTo, $this->extractRetryAfter($headers));
        }
        if (401 === $statusCode || 403 === $statusCode) {
            throw new MarketplaceAuthException('WB API authentication failed.', $statusCode, $excerpt, $dateFrom, $dateTo);
        }
        if ($statusCode >= 500 && $statusCode <= 599) {
            throw new MarketplaceTemporaryApiException('WB API temporary error.', $statusCode, $excerpt, $dateFrom, $dateTo);
        }
        if (200 !== $statusCode) {
            throw new MarketplaceTemporaryApiException('WB API unexpected status.', $statusCode, $excerpt, $dateFrom, $dateTo);
        }
        if ('' === trim($body)) {
            return false;
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MarketplaceInvalidApiResponseException('WB API returned invalid JSON.', $statusCode, $excerpt, $dateFrom, $dateTo, $e);
        }

        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new MarketplaceInvalidApiResponseException('WB API JSON must be a list.', $statusCode, $excerpt, $dateFrom, $dateTo);
        }

        foreach ($decoded as $row) {
            if (!is_array($row)) {
                throw new MarketplaceInvalidApiResponseException('WB API JSON list items must be objects.', $statusCode, $excerpt, $dateFrom, $dateTo);
            }
        }

        return [] !== $decoded;
    }

    private function createSafeExcerpt(string $body): string
    {
        $trimmed = trim($body);
        if ('' === $trimmed) {
            return '';
        }

        return mb_substr($trimmed, 0, 500);
    }

    private function extractRetryAfter(array $headers): ?int
    {
        $retryAfter = $this->extractHeaderInt($headers, self::WB_HEADER_RETRY);
        if ($retryAfter !== null) {
            return $retryAfter;
        }

        $reset = $this->extractHeaderInt($headers, self::WB_HEADER_RESET);
        if ($reset === null) {
            return null;
        }

        $now = (new \DateTimeImmutable())->getTimestamp();

        return $reset > $now ? max(0, $reset - $now) : $reset;
    }

    private function extractHeaderInt(array $headers, string $name): ?int
    {
        $values = $headers[$name] ?? $headers[strtolower($name)] ?? null;
        if (!is_array($values) || [] === $values) {
            return null;
        }

        $value = trim((string) $values[0]);

        return ctype_digit($value) ? (int) $value : null;
    }
}
