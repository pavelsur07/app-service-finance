<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Api\Wildberries;

use App\Marketplace\Exception\MarketplaceAuthException;
use App\Marketplace\Exception\MarketplaceBadRequestException;
use App\Marketplace\Exception\MarketplaceInvalidApiResponseException;
use App\Marketplace\Exception\MarketplaceRateLimitException;
use App\Marketplace\Application\Service\WbFinanceRateLimiter;
use App\Marketplace\Exception\MarketplaceTemporaryApiException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class WbFinanceSalesReportClient
{
    private const BASE_URL = 'https://finance-api.wildberries.ru';
    private const PAGE_SIZE = 100000;
    private const WB_HEADER_RETRY = 'x-ratelimit-retry';
    private const WB_HEADER_RESET = 'x-ratelimit-reset';
    private const WB_MIN_DELAY_SECONDS = 61;

    private LoggerInterface $logger;
    private WbFinanceRateLimiter $rateLimiter;

    public function __construct(
        private HttpClientInterface $httpClient,
        private ClockInterface $clock,
        ?LoggerInterface $logger = null,
        ?WbFinanceRateLimiter $rateLimiter = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->rateLimiter = $rateLimiter ?? new WbFinanceRateLimiter();
    }


    /** @return list<array<string,mixed>> */
    public function fetchDetailedDay(string $connectionId, string $apiKey, \DateTimeImmutable $businessDate): array
    {
        $date = $businessDate->format('Y-m-d');

        return $this->fetchDetailedInternal($apiKey, $date, $date, $connectionId);
    }

    /** @return list<array<string,mixed>> */
    public function fetchDetailed(string $apiKey, string $dateFrom, string $dateTo): array
    {
        return $this->fetchDetailedInternal($apiKey, $dateFrom, $dateTo);
    }

    /** @return list<array<string,mixed>> */
    private function fetchDetailedInternal(string $apiKey, string $dateFrom, string $dateTo, ?string $connectionId = null): array
    {
        $rows = [];
        $rrdId = 0;

        while (true) {
            if (null !== $connectionId && !$this->rateLimiter->acquire($connectionId)) {
                throw new MarketplaceRateLimitException(429, 'Local WB finance rate limit exceeded.', $dateFrom, $dateTo, self::WB_MIN_DELAY_SECONDS);
            }
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
                $this->logWbResponse($statusCode, $dateFrom, $dateTo, $rrdId, self::PAGE_SIZE, 0, $headers, $excerpt);
                return $rows;
            }
            $recordsReceived = null;

            if (429 === $statusCode) {
                throw new MarketplaceRateLimitException($statusCode, $excerpt, $dateFrom, $dateTo, $this->extractRetryAfter($headers));
            }
            if (401 === $statusCode || 403 === $statusCode) {
                throw new MarketplaceAuthException('WB API authentication failed.', $statusCode, $excerpt, $dateFrom, $dateTo);
            }
            if (400 === $statusCode) {
                throw new MarketplaceBadRequestException('WB API rejected request payload.', $statusCode, $excerpt, $dateFrom, $dateTo);
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
            $recordsReceived = count($decoded);
            $this->logWbResponse($statusCode, $dateFrom, $dateTo, $rrdId, self::PAGE_SIZE, $recordsReceived, $headers, $excerpt);

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

            if (count($decoded) < self::PAGE_SIZE) {
                return $rows;
            }

            $this->clock->sleep(self::WB_MIN_DELAY_SECONDS);
        }
    }



    public function probeAccess(string $apiKey): bool
    {
        try {
            $response = $this->httpClient->request('GET', self::BASE_URL.'/ping', [
                'headers' => ['Authorization' => $apiKey],
                'timeout' => 120,
            ]);
            $statusCode = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new MarketplaceTemporaryApiException('WB API transport error.', 0, '', '', '', $e);
        }

        $excerpt = $this->createSafeExcerpt($body);
        $this->logger->info('WB finance ping response.', [
            'endpoint' => 'wildberries::finance-ping',
            'status_code' => $statusCode,
            'response_excerpt' => $excerpt,
            'retry_after' => $this->extractHeaderInt($headers, 'retry-after'),
            'x_ratelimit_retry' => $this->extractHeaderInt($headers, self::WB_HEADER_RETRY),
            'x_ratelimit_reset' => $this->extractHeaderInt($headers, self::WB_HEADER_RESET),
        ]);

        if (200 === $statusCode) {
            if ('' === trim($body)) {
                $this->logger->warning('WB finance ping returned 200 with empty body.', ['response_excerpt' => $excerpt]);

                return true;
            }

            try {
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $this->logger->warning('WB finance ping returned non-JSON body with 200.', ['response_excerpt' => $excerpt]);

                return true;
            }

            if (is_array($decoded) && 'OK' === (string) ($decoded['Status'] ?? '')) {
                return true;
            }

            $this->logger->warning('WB finance ping returned 200 with unexpected JSON status.', ['response_excerpt' => $excerpt]);

            return true;
        }

        if (401 === $statusCode || 403 === $statusCode) {
            return false;
        }
        if (429 === $statusCode) {
            throw new MarketplaceRateLimitException($statusCode, $excerpt, '', '', $this->extractRetryAfter($headers));
        }
        if (400 === $statusCode) {
            throw new MarketplaceBadRequestException('WB finance ping rejected request.', $statusCode, $excerpt, '', '');
        }
        if ($statusCode >= 500 && $statusCode <= 599) {
            throw new MarketplaceTemporaryApiException('WB API temporary error.', $statusCode, $excerpt, '', '');
        }

        throw new MarketplaceTemporaryApiException('WB API unexpected status.', $statusCode, $excerpt, '', '');
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

    private function logWbResponse(
        int $statusCode,
        string $dateFrom,
        string $dateTo,
        int $rrdId,
        int $limit,
        ?int $recordsReceived,
        array $headers,
        string $excerpt,
    ): void {
        $this->logger->info('WB finance sales report response.', [
            'endpoint' => 'wildberries::finance-sales-reports-detailed',
            'status_code' => $statusCode,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'rrdId' => $rrdId,
            'limit' => $limit,
            'records_received' => $recordsReceived,
            'retry_after' => $this->extractHeaderInt($headers, 'retry-after'),
            'x_ratelimit_retry' => $this->extractHeaderInt($headers, self::WB_HEADER_RETRY),
            'x_ratelimit_reset' => $this->extractHeaderInt($headers, self::WB_HEADER_RESET),
            'response_excerpt' => $excerpt,
        ]);
    }
}
