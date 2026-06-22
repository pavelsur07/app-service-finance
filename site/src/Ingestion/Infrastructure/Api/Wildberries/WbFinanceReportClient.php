<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Api\Wildberries;

use App\Ingestion\Exception\ConnectorAuthException;
use App\Ingestion\Exception\ConnectorRateLimitedException;
use App\Ingestion\Exception\ConnectorTransientException;
use App\Ingestion\Exception\CredentialNotFoundException;
use App\Marketplace\Application\Service\WbFinanceRateLimiter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class WbFinanceReportClient implements WbFinanceReportClientInterface
{
    public const PAGE_SIZE = 100000;

    private const BASE_URL = 'https://finance-api.wildberries.ru';
    private const ENDPOINT = '/api/finance/v1/sales-reports/detailed';
    private const DEFAULT_RETRY_AFTER_SECONDS = 70;
    private const GLOBAL_SELLER_BUCKET = 'global';

    public function __construct(
        private HttpClientInterface $httpClient,
        private WbCredentialProviderInterface $credentialProvider,
        private WbFinanceRateLimiter $rateLimiter,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function fetchDetailedDayPage(
        string $companyId,
        string $connectionRef,
        \DateTimeImmutable $date,
        int $rrdId,
        int $limit = self::PAGE_SIZE,
    ): WbFinanceReportPage {
        if ($rrdId < 0) {
            throw new \InvalidArgumentException('WB finance report rrdId cannot be negative.');
        }
        if ($limit < 1 || $limit > self::PAGE_SIZE) {
            throw new \InvalidArgumentException(sprintf('WB finance report limit must be between 1 and %d.', self::PAGE_SIZE));
        }

        $this->consumeRateLimit($connectionRef);
        $credentials = $this->credentials($companyId, $connectionRef);
        $dateString = $date->format('Y-m-d');
        $startedAt = microtime(true);
        $statusCode = null;

        try {
            $response = $this->httpClient->request('POST', self::BASE_URL.self::ENDPOINT, [
                'headers' => [
                    'Authorization' => $credentials['api_key'],
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'dateFrom' => $dateString,
                    'dateTo' => $dateString,
                    'limit' => $limit,
                    'rrdId' => $rrdId,
                    'period' => 'daily',
                ],
                'timeout' => 120,
            ]);

            $statusCode = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $exception) {
            throw new ConnectorTransientException('WB finance report transport error.', previous: $exception);
        } finally {
            $this->logger->info('WB finance report API request finished.', [
                'companyId' => $companyId,
                'connectionRef' => $connectionRef,
                'endpoint' => self::ENDPOINT,
                'date' => $dateString,
                'rrdId' => $rrdId,
                'limit' => $limit,
                'statusCode' => $statusCode,
                'durationMs' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);
        }

        if (204 === $statusCode) {
            return new WbFinanceReportPage(
                rows: [],
                nextRrdId: null,
                hasMore: false,
                metadata: $this->metadata($dateString, $rrdId, $limit, 0, $statusCode),
            );
        }

        $this->classifyStatus($statusCode, $headers, $connectionRef);
        $rows = $this->decodeRows($body);
        if ([] === $rows) {
            return new WbFinanceReportPage(
                rows: [],
                nextRrdId: null,
                hasMore: false,
                metadata: $this->metadata($dateString, $rrdId, $limit, 0, $statusCode),
            );
        }

        $last = $rows[array_key_last($rows)];
        $nextRrdId = $this->rrdId($last);
        if (null === $nextRrdId || $nextRrdId <= $rrdId) {
            throw new \RuntimeException('WB finance report pagination rrdId must grow monotonically.');
        }

        return new WbFinanceReportPage(
            rows: $rows,
            nextRrdId: $nextRrdId,
            hasMore: count($rows) >= $limit,
            metadata: $this->metadata($dateString, $rrdId, $limit, count($rows), $statusCode, $nextRrdId),
        );
    }

    private function consumeRateLimit(string $connectionRef): void
    {
        $sellerBucketId = $this->sellerBucketId($connectionRef);
        $cooldownUntil = $this->rateLimiter->getActiveSalesReportsCooldownUntil($sellerBucketId);
        if (null !== $cooldownUntil) {
            throw new ConnectorRateLimitedException(
                'WB finance report shared cooldown is active.',
                $this->rateLimiter->secondsUntil($cooldownUntil),
            );
        }

        $retryAfter = $this->rateLimiter->tryConsume(
            $this->rateLimiter->buildSalesReportsRateLimitKeyForSellerBucket($sellerBucketId),
        );
        if (null === $retryAfter) {
            return;
        }

        throw new ConnectorRateLimitedException(
            'WB finance report local rate limit is active.',
            $this->rateLimiter->secondsUntil($retryAfter),
        );
    }

    /**
     * @return array{api_key: string}
     */
    private function credentials(string $companyId, string $connectionRef): array
    {
        try {
            $credentials = $this->credentialProvider->read($companyId, $connectionRef);
        } catch (CredentialNotFoundException $exception) {
            throw new ConnectorAuthException('WB finance credentials were not found.', previous: $exception);
        }

        $apiKey = trim((string) ($credentials['api_key'] ?? ''));
        if ('' === $apiKey) {
            throw new ConnectorAuthException('WB finance credentials are incomplete.');
        }

        return ['api_key' => $apiKey];
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function classifyStatus(int $statusCode, array $headers, string $connectionRef): void
    {
        if (401 === $statusCode || 403 === $statusCode) {
            throw new ConnectorAuthException('WB finance API authentication failed.');
        }

        if (429 === $statusCode) {
            $retryAfterSeconds = $this->retryAfterSeconds($headers);
            $sellerBucketId = $this->sellerBucketId($connectionRef);
            $cooldownUntil = $this->rateLimiter->cooldownUntilAfterRemote429($retryAfterSeconds, self::DEFAULT_RETRY_AFTER_SECONDS);
            $this->rateLimiter->setSalesReportsCooldownUntil($sellerBucketId, $cooldownUntil);

            throw new ConnectorRateLimitedException(
                'WB finance API remote rate limit is active.',
                $this->rateLimiter->secondsUntil($cooldownUntil),
            );
        }

        if ($statusCode >= 500) {
            throw new ConnectorTransientException(sprintf('WB finance API server error %d.', $statusCode));
        }

        if (200 !== $statusCode) {
            throw new \RuntimeException(sprintf('WB finance API returned HTTP %d.', $statusCode));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodeRows(string $body): array
    {
        if ('' === trim($body)) {
            throw new \RuntimeException('WB finance API returned an empty response body for HTTP 200.');
        }

        try {
            $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('WB finance API returned invalid JSON.', previous: $exception);
        }

        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new \RuntimeException('WB finance API response must be a JSON list.');
        }

        $rows = [];
        foreach ($decoded as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new \RuntimeException('WB finance API response rows must be JSON objects.');
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rrdId(array $row): ?int
    {
        foreach (['rrdId', 'rrd_id'] as $key) {
            $value = $row[$key] ?? null;
            if (is_int($value)) {
                return $value;
            }
            if (is_string($value) && ctype_digit($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function retryAfterSeconds(array $headers): ?int
    {
        foreach (['retry-after', 'x-ratelimit-retry', 'x-ratelimit-reset'] as $headerName) {
            $value = $headers[$headerName][0] ?? null;
            if (null === $value || '' === trim($value)) {
                continue;
            }

            $seconds = $this->parseRetryHeader(trim($value), 'x-ratelimit-reset' !== $headerName);
            if (null !== $seconds) {
                return $seconds;
            }
        }

        return null;
    }

    private function parseRetryHeader(string $value, bool $allowRelativeSeconds): ?int
    {
        if (ctype_digit($value)) {
            $number = (int) $value;
            if ($allowRelativeSeconds) {
                return max(1, $number);
            }

            return max(1, $number - $this->clock->now()->getTimestamp());
        }

        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }

        return max(1, $date->getTimestamp() - $this->clock->now()->getTimestamp());
    }

    private function sellerBucketId(string $connectionRef): string
    {
        $connectionRef = trim($connectionRef);
        if ('' === $connectionRef) {
            return self::GLOBAL_SELLER_BUCKET;
        }

        return 'connection:'.$connectionRef;
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(
        string $date,
        int $rrdId,
        int $limit,
        int $rows,
        int $statusCode,
        ?int $nextRrdId = null,
    ): array {
        return [
            'endpoint' => self::ENDPOINT,
            'date' => $date,
            'rrdId' => $rrdId,
            'limit' => $limit,
            'rows' => $rows,
            'statusCode' => $statusCode,
            'nextRrdId' => $nextRrdId,
        ];
    }
}
