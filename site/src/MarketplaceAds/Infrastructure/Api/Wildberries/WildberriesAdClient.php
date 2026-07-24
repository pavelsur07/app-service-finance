<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Infrastructure\Api\Wildberries;

use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAds\Exception\WildberriesAdAuthException;
use App\MarketplaceAds\Exception\WildberriesAdRateLimitException;
use App\MarketplaceAds\Exception\WildberriesAdTransientException;
use App\MarketplaceAds\Infrastructure\Api\Contract\AdPlatformClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Read-only client for the current Wildberries Promotion API.
 *
 * Financial actuals come from /adv/v1/upd. /adv/v3/fullstats is requested only
 * for campaigns present in that expense response and is retained as analytics
 * used later for SKU allocation.
 */
final readonly class WildberriesAdClient implements AdPlatformClientInterface
{
    public const FULL_STATS_BATCH_SIZE = 50;
    public const FULL_STATS_INTERVAL_SECONDS = 20;

    private const BASE_URL = 'https://advert-api.wildberries.ru';
    private const EXPENSES_PATH = '/adv/v1/upd';
    private const FULL_STATS_PATH = '/adv/v3/fullstats';
    private const REQUEST_TIMEOUT_SECONDS = 60;
    private const DEFAULT_RETRY_AFTER_SECONDS = 20;

    public function __construct(
        private HttpClientInterface $httpClient,
        private MarketplaceFacade $marketplaceFacade,
        private WildberriesJsonDecoder $jsonDecoder,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function supports(string $marketplace): bool
    {
        return MarketplaceType::WILDBERRIES->value === $marketplace;
    }

    public function getRequiredConnectionType(): MarketplaceConnectionType
    {
        return MarketplaceConnectionType::SELLER;
    }

    public function fetchAdStatistics(string $companyId, \DateTimeImmutable $date): string
    {
        return $this->fetchAdStatisticsForConnection($companyId, null, $date);
    }

    public function fetchAdStatisticsForConnection(
        string $companyId,
        ?string $connectionId,
        \DateTimeImmutable $date,
    ): string {
        $expenses = $this->fetchExpenses($companyId, $connectionId, $date);
        $campaignIds = $this->campaignIdsFromExpenses($expenses);
        $statistics = [];
        $batches = array_chunk($campaignIds, self::FULL_STATS_BATCH_SIZE);

        foreach ($batches as $index => $batch) {
            if ($index > 0) {
                $this->clock->sleep(self::FULL_STATS_INTERVAL_SECONDS);
            }

            array_push(
                $statistics,
                ...$this->fetchFullStatistics($companyId, $connectionId, $date, $batch),
            );
        }

        return json_encode([
            'schema' => 'wb-ad-daily-spend-v1',
            'expenses' => $expenses,
            'statistics' => $statistics,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchExpenses(
        string $companyId,
        ?string $connectionId,
        \DateTimeImmutable $date,
    ): array {
        $day = $date->format('Y-m-d');

        return $this->requestList(
            companyId: $companyId,
            connectionId: $connectionId,
            path: self::EXPENSES_PATH,
            query: ['from' => $day, 'to' => $day],
            operation: 'expenses',
            date: $day,
            campaignCount: null,
        );
    }

    /**
     * @param list<string> $campaignIds
     *
     * @return list<array<string, mixed>>
     */
    public function fetchFullStatistics(
        string $companyId,
        ?string $connectionId,
        \DateTimeImmutable $date,
        array $campaignIds,
    ): array {
        $campaignIds = array_values(array_unique($campaignIds));
        $count = count($campaignIds);
        if ($count < 1 || $count > self::FULL_STATS_BATCH_SIZE) {
            throw new \InvalidArgumentException(sprintf('WB fullstats campaign count must be between 1 and %d.', self::FULL_STATS_BATCH_SIZE));
        }
        foreach ($campaignIds as $campaignId) {
            if ('' === $campaignId || !ctype_digit($campaignId)) {
                throw new \InvalidArgumentException('WB fullstats campaign IDs must be non-empty decimal strings.');
            }
        }

        $day = $date->format('Y-m-d');

        return $this->requestList(
            companyId: $companyId,
            connectionId: $connectionId,
            path: self::FULL_STATS_PATH,
            query: [
                'ids' => implode(',', $campaignIds),
                'beginDate' => $day,
                'endDate' => $day,
            ],
            operation: 'fullstats',
            date: $day,
            campaignCount: $count,
        );
    }

    /**
     * @param array<string, string> $query
     *
     * @return list<array<string, mixed>>
     */
    private function requestList(
        string $companyId,
        ?string $connectionId,
        string $path,
        array $query,
        string $operation,
        string $date,
        ?int $campaignCount,
    ): array {
        $token = $this->token($companyId, $connectionId);
        $startedAt = microtime(true);
        $statusCode = null;
        $headers = [];

        try {
            $response = $this->httpClient->request('GET', self::BASE_URL.$path, [
                'headers' => ['Authorization' => $token],
                'query' => $query,
                'timeout' => self::REQUEST_TIMEOUT_SECONDS,
            ]);
            $statusCode = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $exception) {
            throw new WildberriesAdTransientException('WB Promotion API transport error.', previous: $exception);
        } finally {
            $this->logger->info('WB Promotion API request finished.', [
                'companyId' => $companyId,
                'connectionId' => $connectionId,
                'operation' => $operation,
                'date' => $date,
                'campaignCount' => $campaignCount,
                'statusCode' => $statusCode,
                'durationMs' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);
        }

        $this->classifyStatus($statusCode, $headers);

        try {
            return $this->jsonDecoder->decodeObjectList($body);
        } catch (\JsonException|\UnexpectedValueException $exception) {
            throw new WildberriesAdTransientException('WB Promotion API returned an invalid JSON list.', previous: $exception);
        }
    }

    private function token(string $companyId, ?string $connectionId): string
    {
        $credentials = $this->marketplaceFacade->getConnectionCredentials(
            $companyId,
            MarketplaceType::WILDBERRIES,
            MarketplaceConnectionType::SELLER,
            $connectionId,
        );
        $token = trim((string) ($credentials['api_key'] ?? ''));
        if ('' === $token) {
            throw new WildberriesAdAuthException('WB Promotion API credentials are missing or incomplete.');
        }

        return $token;
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function classifyStatus(int $statusCode, array $headers): void
    {
        if (401 === $statusCode || 403 === $statusCode) {
            throw new WildberriesAdAuthException('WB Promotion API authentication failed.');
        }
        if (429 === $statusCode) {
            throw new WildberriesAdRateLimitException('WB Promotion API rate limit exceeded.', $this->retryAfterSeconds($headers));
        }
        if ($statusCode >= 500) {
            throw new WildberriesAdTransientException(sprintf('WB Promotion API server error %d.', $statusCode));
        }
        if (200 !== $statusCode) {
            throw new \RuntimeException(sprintf('WB Promotion API returned unexpected HTTP %d.', $statusCode));
        }
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function retryAfterSeconds(array $headers): int
    {
        $value = trim((string) ($headers['retry-after'][0] ?? ''));

        return ctype_digit($value) && (int) $value > 0
            ? (int) $value
            : self::DEFAULT_RETRY_AFTER_SECONDS;
    }

    /**
     * @param list<array<string, mixed>> $expenses
     *
     * @return list<string>
     */
    private function campaignIdsFromExpenses(array $expenses): array
    {
        $ids = [];
        $seen = [];
        foreach ($expenses as $row) {
            $advertId = $row['advertId'] ?? null;
            if (!is_string($advertId) || !ctype_digit($advertId)) {
                throw new WildberriesAdTransientException('WB expenses response contains an invalid advertId.');
            }

            if (!isset($seen['id:'.$advertId])) {
                $seen['id:'.$advertId] = true;
                $ids[] = $advertId;
            }
        }

        sort($ids, \SORT_NATURAL);

        return $ids;
    }
}
