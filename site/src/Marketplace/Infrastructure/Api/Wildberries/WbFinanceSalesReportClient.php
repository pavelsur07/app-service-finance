<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Api\Wildberries;

use App\Marketplace\Application\Service\WbFinanceRateLimiter;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Exception\MarketplaceAuthException;
use App\Marketplace\Exception\MarketplaceBadRequestException;
use App\Marketplace\Exception\MarketplaceInvalidApiResponseException;
use App\Marketplace\Exception\MarketplaceRateLimitException;
use App\Marketplace\Exception\MarketplaceTemporaryApiException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class WbFinanceSalesReportClient
{
    private const BASE_URL = 'https://finance-api.wildberries.ru';
    public const PAGE_SIZE = 100000;
    private const WB_HEADER_RETRY = 'x-ratelimit-retry';
    private const WB_HEADER_RESET = 'x-ratelimit-reset';
    private const SALES_REPORTS_RATE_LIMIT_KEY_PREFIX = 'wb_finance_sales_reports';
    private const GLOBAL_SELLER_BUCKET = 'global';

    private LoggerInterface $logger;
    private WbFinanceRateLimiter $rateLimiter;

    public function __construct(
        private HttpClientInterface $httpClient,
        WbFinanceRateLimiter $rateLimiter,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->rateLimiter = $rateLimiter;
    }

    /** @return list<array<string,mixed>> */
    public function fetchDetailedDay(string $connectionId, string $apiKey, \DateTimeImmutable $businessDate, ?string $sellerBucketId = null): array
    {
        $date = $businessDate->format('Y-m-d');

        return $this->fetchDetailedInternal($apiKey, $date, $date, $sellerBucketId, $connectionId);
    }

    public function fetchDetailedDayPage(string $connectionId, string $apiKey, \DateTimeImmutable $businessDate, int $rrdId, bool $rateLimitTokenConsumed = false, ?string $sellerBucketId = null): WbFinanceSalesReportPage
    {
        $date = $businessDate->format('Y-m-d');

        return $this->fetchDetailedPage($apiKey, $date, $date, $rrdId, self::PAGE_SIZE, $rateLimitTokenConsumed, $sellerBucketId, $connectionId);
    }

    /**
     * The $connectionId parameter scopes throttling when seller/account identifiers are unavailable.
     *
     * @return list<array<string,mixed>>
     */
    public function fetchDetailedForConnection(string $connectionId, string $apiKey, string $dateFrom, string $dateTo, ?string $sellerBucketId = null): array
    {
        return $this->fetchDetailedInternal($apiKey, $dateFrom, $dateTo, $sellerBucketId, $connectionId);
    }

    public function tryConsume(string $sellerRateLimitKey): ?\DateTimeImmutable
    {
        return $this->rateLimiter->tryConsume($sellerRateLimitKey);
    }

    public function resolveSalesReportsBucketId(MarketplaceConnection $connection): string
    {
        return $this->rateLimiter->resolveSalesReportsBucketId($connection);
    }

    public function resolveSalesReportsBucketSource(MarketplaceConnection $connection): string
    {
        return $this->rateLimiter->resolveSalesReportsBucketSource($connection);
    }

    /** @return array{bucket_id: string, bucket_source: string} */
    public function resolveSalesReportsBucket(MarketplaceConnection $connection): array
    {
        return $this->rateLimiter->resolveSalesReportsBucket($connection);
    }

    public function resolveSalesReportsSellerBucketId(MarketplaceConnection $connection): string
    {
        return $this->resolveSalesReportsBucketId($connection);
    }

    public function buildSalesReportsRateLimitKeyForSellerBucket(string $sellerBucketId): string
    {
        return $this->rateLimiter->buildSalesReportsRateLimitKeyForSellerBucket($sellerBucketId);
    }

    public function getActiveSalesReportsCooldownUntil(string $sellerBucketId): ?\DateTimeImmutable
    {
        return $this->rateLimiter->getActiveSalesReportsCooldownUntil($sellerBucketId);
    }

    public function setSalesReportsCooldownUntil(string $sellerBucketId, \DateTimeImmutable $cooldownUntil): void
    {
        $this->rateLimiter->setSalesReportsCooldownUntil($sellerBucketId, $cooldownUntil);
    }

    public function cooldownUntilAfterRemote429(?int $retryAfterSeconds, int $defaultSeconds = 70): \DateTimeImmutable
    {
        return $this->rateLimiter->cooldownUntilAfterRemote429($retryAfterSeconds, $defaultSeconds);
    }

    public function buildSalesReportsCooldownKey(string $sellerBucketId): string
    {
        return $this->rateLimiter->buildSalesReportsCooldownKey($sellerBucketId);
    }

    public function buildSalesReportsRateLimitKeyForApiKey(string $apiKey): string
    {
        return $this->buildSalesReportsRateLimitKey(hash('sha256', $apiKey));
    }

    /**
     * @deprecated use fetchDetailedForConnection() in production flows when connection context is available; unknown context uses the shared global bucket
     *
     * @return list<array<string,mixed>>
     */
    public function fetchDetailed(string $apiKey, string $dateFrom, string $dateTo): array
    {
        return $this->fetchDetailedInternal($apiKey, $dateFrom, $dateTo, null, null);
    }

    /** @return list<array<string,mixed>> */
    private function fetchDetailedInternal(string $apiKey, string $dateFrom, string $dateTo, ?string $sellerBucketId, ?string $connectionId): array
    {
        $rows = [];
        $rrdId = 0;

        do {
            $page = $this->fetchDetailedPage($apiKey, $dateFrom, $dateTo, $rrdId, self::PAGE_SIZE, false, $sellerBucketId, $connectionId);
            $rows = [...$rows, ...$page->rows];
            $rrdId = $page->nextRrdId ?? $rrdId;
        } while ($page->hasNextPage);

        return $rows;
    }

    private function fetchDetailedPage(string $apiKey, string $dateFrom, string $dateTo, int $rrdId, int $limit, bool $rateLimitTokenConsumed = false, ?string $sellerBucketId = null, ?string $connectionId = null): WbFinanceSalesReportPage
    {
        $sellerBucketId = $this->normalizeSellerBucketId($sellerBucketId, $connectionId);
        $this->guardCooldown($sellerBucketId, $dateFrom, $dateTo);

        if (!$rateLimitTokenConsumed) {
            $retryAfter = $this->rateLimiter->tryConsume($this->rateLimiter->buildSalesReportsRateLimitKeyForSellerBucket($sellerBucketId));
            if (null !== $retryAfter) {
                throw new MarketplaceRateLimitException(429, '', $dateFrom, $dateTo, $this->rateLimiter->secondsUntil($retryAfter));
            }
        }

        try {
            $response = $this->httpClient->request('POST', self::BASE_URL.'/api/finance/v1/sales-reports/detailed', [
                'headers' => ['Authorization' => $apiKey],
                'json' => [
                    'dateFrom' => $dateFrom,
                    'dateTo' => $dateTo,
                    'limit' => $limit,
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
            $this->logWbResponse($statusCode, $dateFrom, $dateTo, $rrdId, $limit, 0, $headers, $excerpt);

            return new WbFinanceSalesReportPage([], null, false);
        }

        if (429 === $statusCode) {
            $retryAfter = $this->handleRemote429($sellerBucketId, $headers, $statusCode, $dateFrom, $dateTo, $rrdId, $limit, $excerpt, 'wildberries::finance-sales-reports-detailed');

            throw new MarketplaceRateLimitException($statusCode, $excerpt, $dateFrom, $dateTo, $retryAfter);
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
            $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
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
        $this->logWbResponse($statusCode, $dateFrom, $dateTo, $rrdId, $limit, $recordsReceived, $headers, $excerpt);

        if ([] === $decoded) {
            return new WbFinanceSalesReportPage([], null, false);
        }

        $last = end($decoded);
        $nextRrdId = is_array($last) ? (int) ($last['rrdId'] ?? 0) : 0;
        if ($nextRrdId <= $rrdId) {
            throw new MarketplaceInvalidApiResponseException('WB API pagination rrdId must grow monotonically.', $statusCode, $excerpt, $dateFrom, $dateTo);
        }

        return new WbFinanceSalesReportPage($decoded, $nextRrdId, $recordsReceived >= $limit);
    }

    public function probeAccess(string $apiKey, ?string $sellerBucketId = null): bool
    {
        // Probe is used for explicit credential checks (e.g. verify connection),
        // not for report sync pipeline, so local report throttling is not applied here.
        // Shared cooldown still protects the WB Finance API from requests during a remote 429 cooldown.
        $sellerBucketId = $this->normalizeSellerBucketId($sellerBucketId, null);
        $this->guardCooldown($sellerBucketId, '', '');

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
                $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
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
            $retryAfter = $this->handleRemote429($sellerBucketId, $headers, $statusCode, '', '', null, null, $excerpt, 'wildberries::finance-ping');

            throw new MarketplaceRateLimitException($statusCode, $excerpt, '', '', $retryAfter);
        }
        if (400 === $statusCode) {
            throw new MarketplaceBadRequestException('WB finance ping rejected request.', $statusCode, $excerpt, '', '');
        }
        if ($statusCode >= 500 && $statusCode <= 599) {
            throw new MarketplaceTemporaryApiException('WB API temporary error.', $statusCode, $excerpt, '', '');
        }

        throw new MarketplaceTemporaryApiException('WB API unexpected status.', $statusCode, $excerpt, '', '');
    }

    public function hasAnyDataForConnection(string $connectionId, string $apiKey, string $dateFrom, string $dateTo, ?string $sellerBucketId = null): bool
    {
        $sellerBucketId = $this->normalizeSellerBucketId($sellerBucketId, $connectionId);
        $this->guardCooldown($sellerBucketId, $dateFrom, $dateTo);

        $retryAfter = $this->rateLimiter->tryConsume($this->rateLimiter->buildSalesReportsRateLimitKeyForSellerBucket($sellerBucketId));
        if (null !== $retryAfter) {
            throw new MarketplaceRateLimitException(429, '', $dateFrom, $dateTo, $this->rateLimiter->secondsUntil($retryAfter));
        }

        return $this->hasAnyData($apiKey, $dateFrom, $dateTo, $sellerBucketId, true);
    }

    public function hasAnyData(string $apiKey, string $dateFrom, string $dateTo, ?string $sellerBucketId = null, bool $rateLimitTokenConsumed = false): bool
    {
        $sellerBucketId = $this->normalizeSellerBucketId($sellerBucketId, null);
        $this->guardCooldown($sellerBucketId, $dateFrom, $dateTo);

        if (!$rateLimitTokenConsumed) {
            $retryAfter = $this->rateLimiter->tryConsume($this->rateLimiter->buildSalesReportsRateLimitKeyForSellerBucket($sellerBucketId));
            if (null !== $retryAfter) {
                throw new MarketplaceRateLimitException(429, '', $dateFrom, $dateTo, $this->rateLimiter->secondsUntil($retryAfter));
            }
        }

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
            $retryAfter = $this->handleRemote429($sellerBucketId, $headers, $statusCode, $dateFrom, $dateTo, 0, 1, $excerpt, 'wildberries::finance-sales-reports-detailed');

            throw new MarketplaceRateLimitException($statusCode, $excerpt, $dateFrom, $dateTo, $retryAfter);
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
            $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
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

    private function guardCooldown(string $sellerBucketId, string $dateFrom, string $dateTo): void
    {
        $cooldownUntil = $this->rateLimiter->getActiveSalesReportsCooldownUntil($sellerBucketId);
        if (null === $cooldownUntil) {
            return;
        }

        throw new MarketplaceRateLimitException(429, '', $dateFrom, $dateTo, $this->rateLimiter->secondsUntil($cooldownUntil));
    }

    private function normalizeSellerBucketId(?string $sellerBucketId, ?string $connectionId = null): string
    {
        if (null !== $connectionId && '' !== trim($connectionId)) {
            return 'connection:'.trim($connectionId);
        }

        if (null !== $sellerBucketId && '' !== trim($sellerBucketId)) {
            return $sellerBucketId;
        }

        return self::GLOBAL_SELLER_BUCKET;
    }

    private function buildSalesReportsRateLimitKey(string $tokenHash): string
    {
        return self::SALES_REPORTS_RATE_LIMIT_KEY_PREFIX.':'.$tokenHash;
    }

    private function createSafeExcerpt(string $body): string
    {
        $trimmed = trim($body);
        if ('' === $trimmed) {
            return '';
        }

        return mb_substr($trimmed, 0, 500);
    }

    private function handleRemote429(
        string $sellerBucketId,
        array $headers,
        int $statusCode,
        string $dateFrom,
        string $dateTo,
        ?int $rrdId,
        ?int $limit,
        string $excerpt,
        string $endpoint,
    ): ?int {
        $cooldown = $this->calculateRemote429Cooldown($headers);
        $this->setSalesReportsCooldownUntil($sellerBucketId, $cooldown['cooldown_until']);

        $this->logger->warning('WB finance remote rate limit response.', [
            'endpoint' => $endpoint,
            'status_code' => $statusCode,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'rrdId' => $rrdId,
            'limit' => $limit,
            'seller_bucket_id' => $sellerBucketId,
            'server_time' => $cooldown['server_time']->format(\DateTimeInterface::ATOM),
            'calculated_cooldown_until' => $cooldown['cooldown_until']->format(\DateTimeInterface::ATOM),
            'calculated_retry_after_seconds' => $cooldown['retry_after_seconds'],
            'cooldown_source_header' => $cooldown['source_header'],
            'cooldown_source_value' => $cooldown['source_value'],
            'retry_after' => $this->extractHeaderFirstValue($headers, 'retry-after'),
            'x_ratelimit_retry' => $this->extractHeaderFirstValue($headers, self::WB_HEADER_RETRY),
            'x_ratelimit_reset' => $this->extractHeaderFirstValue($headers, self::WB_HEADER_RESET),
            'x_ratelimit_limit' => $this->extractHeaderFirstValue($headers, 'x-ratelimit-limit'),
            'x_ratelimit_remaining' => $this->extractHeaderFirstValue($headers, 'x-ratelimit-remaining'),
            'response_headers_json' => $this->encodeSafeHeadersJson($headers),
            'response_excerpt' => $excerpt,
        ]);

        return $cooldown['retry_after_seconds'];
    }

    /**
     * @return array{cooldown_until: \DateTimeImmutable, retry_after_seconds: ?int, server_time: \DateTimeImmutable, source_header: ?string, source_value: ?string}
     */
    private function calculateRemote429Cooldown(array $headers): array
    {
        $now = $this->rateLimiter->now();
        $candidates = [
            'retry-after' => true,
            self::WB_HEADER_RETRY => true,
            self::WB_HEADER_RESET => false,
        ];

        foreach ($candidates as $headerName => $allowRelativeSeconds) {
            $value = $this->extractHeaderFirstValue($headers, $headerName);
            if (null === $value) {
                continue;
            }

            $cooldownUntil = $this->parseRetryCooldownHeader($value, $now, $allowRelativeSeconds);

            if (null === $cooldownUntil) {
                continue;
            }

            return [
                'cooldown_until' => $cooldownUntil,
                'retry_after_seconds' => max(1, $cooldownUntil->getTimestamp() - $now->getTimestamp()),
                'server_time' => $now,
                'source_header' => $headerName,
                'source_value' => $value,
            ];
        }

        $fallbackUntil = $this->cooldownUntilAfterRemote429(null);

        return [
            'cooldown_until' => $fallbackUntil,
            'retry_after_seconds' => null,
            'server_time' => $now,
            'source_header' => null,
            'source_value' => null,
        ];
    }

    private function parseRetryCooldownHeader(string $value, \DateTimeImmutable $now, bool $allowRelativeSeconds): ?\DateTimeImmutable
    {
        $trimmed = trim($value);
        if ('' === $trimmed) {
            return null;
        }

        if (ctype_digit($trimmed)) {
            $numericValue = (int) $trimmed;
            if ($allowRelativeSeconds && $numericValue <= 86400) {
                return $now->modify(sprintf('+%d seconds', max(1, $numericValue)));
            }

            if ($numericValue > $now->getTimestamp()) {
                return (new \DateTimeImmutable('@'.$numericValue))->setTimezone($now->getTimezone());
            }

            return null;
        }

        return $this->parseDateTimeHeader($trimmed, $now);
    }

    private function parseDateTimeHeader(string $value, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }

        try {
            $parsed = new \DateTimeImmutable($value, $now->getTimezone());
        } catch (\Exception) {
            return null;
        }

        if ($parsed->getTimestamp() <= $now->getTimestamp()) {
            return null;
        }

        return $parsed->setTimezone($now->getTimezone());
    }

    private function extractRetryAfter(array $headers): ?int
    {
        return $this->calculateRemote429Cooldown($headers)['retry_after_seconds'];
    }

    private function extractHeaderInt(array $headers, string $name): ?int
    {
        $value = $this->extractHeaderFirstValue($headers, $name);

        return null !== $value && ctype_digit(trim($value)) ? (int) trim($value) : null;
    }

    private function extractHeaderFirstValue(array $headers, string $name): ?string
    {
        $values = $headers[$name] ?? $headers[strtolower($name)] ?? null;
        if (!is_array($values) || [] === $values) {
            return null;
        }

        return trim((string) $values[0]);
    }

    private function encodeSafeHeadersJson(array $headers): string
    {
        $normalized = [];
        foreach ($headers as $name => $values) {
            $normalized[(string) $name] = array_map(
                static fn (mixed $value): string => mb_substr((string) $value, 0, 1000),
                is_array($values) ? $values : [$values],
            );
        }

        return json_encode($normalized, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
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
