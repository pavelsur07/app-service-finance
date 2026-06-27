<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Api\Ozon;

use App\Ingestion\Exception\ConnectorAuthException;
use App\Ingestion\Exception\ConnectorRateLimitedException;
use App\Ingestion\Exception\ConnectorTransientException;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class OzonPerformanceReportClient implements OzonPerformanceReportClientInterface
{
    private const TOKEN_PATH = '/api/client/token';
    private const CAMPAIGN_PATH = '/api/client/campaign';
    private const CAMPAIGN_OBJECTS_PATH = '/api/client/campaign/%s/objects';
    private const SEARCH_PROMO_PRODUCTS_PATH = '/api/client/campaign/search_promo/v2/products';
    private const PRODUCT_STATISTICS_PATH = '/api/client/statistics/campaign/product/json';
    private const PRODUCTS_REPORT_PATH = '/api/client/statistic/products/generate';
    private const ORDERS_REPORT_PATH = '/api/client/statistic/orders/generate';
    private const REPORT_STATE_PATH = '/api/client/statistics/%s';
    private const REPORT_DOWNLOAD_PATH = '/api/client/statistics/report';
    private const EXPENSE_STATISTICS_PATH = '/api/client/statistics/expense/json';

    private const REQUEST_TIMEOUT = 60;
    private const TOKEN_TTL_SAFETY_MARGIN = 300;
    private const CACHE_KEY_TOKEN_PREFIX = 'ingestion_ozon_perf_token_';
    private const CACHE_KEY_CAMPAIGNS_PREFIX = 'ingestion_ozon_perf_campaigns_';
    private const CAMPAIGN_CACHE_TTL_SECONDS = 300;

    private string $baseUrl;

    public function __construct(
        private HttpClientInterface $httpClient,
        private MarketplaceFacade $marketplaceFacade,
        private CacheInterface $cache,
        private LoggerInterface $logger,
        string $baseUrl,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function listCampaigns(string $companyId, string $connectionRef, array $advObjectTypes = []): OzonRawPage
    {
        $advObjectTypes = $this->normalizeStringList($advObjectTypes);

        $rows = [];
        $resultKeys = [];
        foreach ([] === $advObjectTypes ? [null] : $advObjectTypes as $advObjectType) {
            $page = $this->cachedCampaignPage($companyId, $connectionRef, $advObjectType);
            array_push($rows, ...$page->rows);
            foreach ($page->metadata['resultKeys'] ?? [] as $key) {
                if (is_string($key) && '' !== $key) {
                    $resultKeys[$key] = true;
                }
            }
        }

        return new OzonRawPage($this->sortRows($rows), false, null, [
            'endpoint' => self::CAMPAIGN_PATH,
            'advObjectTypes' => $advObjectTypes,
            'resultKeys' => array_keys($resultKeys),
        ]);
    }

    private function cachedCampaignPage(string $companyId, string $connectionRef, ?string $advObjectType): OzonRawPage
    {
        $advObjectTypes = null === $advObjectType ? [] : [$advObjectType];
        $cacheKey = $this->campaignCacheKey($companyId, $connectionRef, $advObjectTypes);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($companyId, $connectionRef, $advObjectType, $advObjectTypes): OzonRawPage {
            $item->expiresAfter(self::CAMPAIGN_CACHE_TTL_SECONDS);

            $options = null === $advObjectType ? [] : ['query' => ['advObjectType' => $advObjectType]];
            $payload = $this->requestJson($companyId, $connectionRef, 'GET', self::CAMPAIGN_PATH, $options);

            return new OzonRawPage($this->sortRows($this->extractRows($payload)), false, null, [
                'endpoint' => self::CAMPAIGN_PATH,
                'advObjectTypes' => $advObjectTypes,
                'resultKeys' => array_keys($payload),
            ]);
        });
    }

    public function fetchCampaignObjects(string $companyId, string $connectionRef, string $campaignId): OzonRawPage
    {
        $endpoint = sprintf(self::CAMPAIGN_OBJECTS_PATH, rawurlencode($campaignId));
        $payload = $this->requestJson($companyId, $connectionRef, 'GET', $endpoint);

        return new OzonRawPage($this->rowsWithMetadata($this->extractRows($payload), ['campaignId' => $campaignId]), false, null, [
            'endpoint' => $endpoint,
            'campaignId' => $campaignId,
            'resultKeys' => array_keys($payload),
        ]);
    }

    public function fetchSearchPromoProducts(string $companyId, string $connectionRef, string $campaignId, int $page): OzonRawPage
    {
        $page = max(1, $page);
        $payload = $this->requestJson($companyId, $connectionRef, 'POST', self::SEARCH_PROMO_PRODUCTS_PATH, [
            'json' => [
                'campaignId' => $campaignId,
                'page' => $page,
                'pageSize' => 1000,
            ],
        ]);
        $rows = $this->rowsWithMetadata($this->extractRows($payload), ['campaignId' => $campaignId, 'page' => $page]);
        $hasMore = $this->hasMore($payload, $page, count($rows), 1000);

        return new OzonRawPage($this->sortRows($rows), $hasMore, $hasMore ? (string) ($page + 1) : null, [
            'endpoint' => self::SEARCH_PROMO_PRODUCTS_PATH,
            'campaignId' => $campaignId,
            'page' => $page,
            'resultKeys' => array_keys($payload),
        ]);
    }

    public function fetchSkuProductStatistics(
        string $companyId,
        string $connectionRef,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        array $campaignIds,
    ): OzonRawPage {
        $campaignIds = $this->normalizeCampaignIds($campaignIds);
        $payload = $this->requestJson($companyId, $connectionRef, 'GET', self::PRODUCT_STATISTICS_PATH, [
            'query' => [
                'dateFrom' => $dateFrom->format('Y-m-d'),
                'dateTo' => $dateTo->format('Y-m-d'),
                'from' => $this->startOfDayUtc($dateFrom),
                'to' => $this->endOfDayUtc($dateTo),
                'campaignIds' => $campaignIds,
            ],
        ]);

        return new OzonRawPage($this->rowsWithMetadata($this->extractRows($payload), ['campaignIds' => $campaignIds]), false, null, [
            'endpoint' => self::PRODUCT_STATISTICS_PATH,
            'campaignIds' => $campaignIds,
            'windowFrom' => $dateFrom->format('Y-m-d'),
            'windowTo' => $dateTo->format('Y-m-d'),
            'resultKeys' => array_keys($payload),
        ]);
    }

    public function generateSearchPromoReport(
        string $companyId,
        string $connectionRef,
        string $reportType,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        array $campaignIds,
    ): string {
        $campaignIds = $this->normalizeCampaignIds($campaignIds);
        $endpoint = match ($reportType) {
            'products' => self::PRODUCTS_REPORT_PATH,
            'orders' => self::ORDERS_REPORT_PATH,
            default => throw new \InvalidArgumentException(sprintf('Unsupported Ozon Search Promo report type "%s".', $reportType)),
        };

        $payload = $this->requestJson($companyId, $connectionRef, 'POST', $endpoint, [
            'json' => [
                'campaigns' => $campaignIds,
                'campaignIds' => $campaignIds,
                'dateFrom' => $dateFrom->format('Y-m-d'),
                'dateTo' => $dateTo->format('Y-m-d'),
                'from' => $this->startOfDayUtc($dateFrom),
                'to' => $this->endOfDayUtc($dateTo),
            ],
        ]);

        $uuid = $this->stringField($payload['UUID'] ?? $payload['uuid'] ?? $payload['report_uuid'] ?? $payload['reportUuid'] ?? null);
        if ('' === $uuid) {
            throw new ConnectorTransientException(sprintf('Ozon Performance %s report response does not contain UUID.', $reportType));
        }

        return $uuid;
    }

    public function pollReport(string $companyId, string $connectionRef, string $reportUuid): ?string
    {
        $endpoint = sprintf(self::REPORT_STATE_PATH, rawurlencode($reportUuid));
        $payload = $this->requestJson($companyId, $connectionRef, 'GET', $endpoint);
        $state = strtolower($this->stringField($payload['state'] ?? $payload['status'] ?? $payload['result']['state'] ?? $payload['result']['status'] ?? null));

        if (in_array($state, ['error', 'failed', 'failure'], true)) {
            throw new ConnectorTransientException(sprintf('Ozon Performance report %s failed.', $reportUuid));
        }

        $link = $this->stringField($payload['link'] ?? $payload['report']['link'] ?? $payload['result']['link'] ?? $payload['result']['report']['link'] ?? null);
        if ('' !== $link || in_array($state, ['ok', 'ready', 'done', 'success'], true)) {
            return $link;
        }

        return null;
    }

    public function downloadReport(string $companyId, string $connectionRef, string $reportUuid, string $reportLink): OzonRawPage
    {
        $options = '' !== $reportLink
            ? ['absoluteUrl' => $reportLink]
            : ['query' => ['UUID' => $reportUuid]];
        $content = $this->requestContent($companyId, $connectionRef, 'GET', self::REPORT_DOWNLOAD_PATH, $options);
        $rows = $this->decodeReportContent($content);

        return new OzonRawPage($this->rowsWithMetadata($rows, ['reportUuid' => $reportUuid]), false, null, [
            'endpoint' => self::REPORT_DOWNLOAD_PATH,
            'reportUuid' => $reportUuid,
            'reportLinkProvided' => '' !== $reportLink,
        ]);
    }

    public function fetchExpenseStatistics(
        string $companyId,
        string $connectionRef,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
    ): OzonRawPage {
        $payload = $this->requestJson($companyId, $connectionRef, 'GET', self::EXPENSE_STATISTICS_PATH, [
            'query' => [
                'dateFrom' => $dateFrom->format('Y-m-d'),
                'dateTo' => $dateTo->format('Y-m-d'),
                'from' => $this->startOfDayUtc($dateFrom),
                'to' => $this->endOfDayUtc($dateTo),
            ],
        ]);

        return new OzonRawPage($this->sortRows($this->extractRows($payload)), false, null, [
            'endpoint' => self::EXPENSE_STATISTICS_PATH,
            'windowFrom' => $dateFrom->format('Y-m-d'),
            'windowTo' => $dateTo->format('Y-m-d'),
            'resultKeys' => array_keys($payload),
        ]);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function requestJson(string $companyId, string $connectionRef, string $method, string $endpoint, array $options = []): array
    {
        $content = $this->requestContent($companyId, $connectionRef, $method, $endpoint, $options);

        try {
            $payload = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ConnectorTransientException(sprintf('Ozon Performance returned invalid JSON for %s.', $endpoint), previous: $exception);
        }

        if (!is_array($payload)) {
            throw new ConnectorTransientException(sprintf('Ozon Performance returned unexpected payload for %s.', $endpoint));
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function requestContent(string $companyId, string $connectionRef, string $method, string $endpoint, array $options = []): string
    {
        $credentials = $this->credentials($companyId, $connectionRef);
        $token = $this->accessToken($companyId, $connectionRef, $credentials['client_id'], $credentials['client_secret'], false);
        $response = $this->requestWithToken($companyId, $connectionRef, $method, $endpoint, $token, $options);
        if (401 === $response->getStatusCode()) {
            $token = $this->accessToken($companyId, $connectionRef, $credentials['client_id'], $credentials['client_secret'], true);
            $response = $this->requestWithToken($companyId, $connectionRef, $method, $endpoint, $token, $options);
        }

        $statusCode = $response->getStatusCode();
        $content = $response->getContent(false);
        $this->classifyStatus($statusCode, $endpoint, $content);

        return $content;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function requestWithToken(string $companyId, string $connectionRef, string $method, string $endpoint, string $token, array $options): ResponseInterface
    {
        $absoluteUrl = isset($options['absoluteUrl']) && is_string($options['absoluteUrl']) && '' !== $options['absoluteUrl'];
        $url = $absoluteUrl
            ? $this->normalizeReportUrl($options['absoluteUrl'])
            : $this->baseUrl.$endpoint;
        unset($options['absoluteUrl']);

        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Accept' => 'application/json',
        ]);
        if (!$absoluteUrl || !$this->isExternalUrl($url)) {
            $options['headers']['Authorization'] = 'Bearer '.$token;
        }
        $options['timeout'] ??= self::REQUEST_TIMEOUT;

        $startedAt = microtime(true);
        $statusCode = null;
        try {
            $response = $this->httpClient->request($method, $url, $options);
            $statusCode = $response->getStatusCode();

            return $response;
        } catch (TransportExceptionInterface $exception) {
            throw new ConnectorTransientException(sprintf('Ozon Performance transport error for %s.', $endpoint), previous: $exception);
        } finally {
            $this->logger->info('Ozon Performance API request finished.', [
                'companyId' => $companyId,
                'connectionRef' => $connectionRef,
                'endpoint' => $endpoint,
                'method' => $method,
                'statusCode' => $statusCode,
                'durationMs' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);
        }
    }

    /**
     * @return array{client_id: string, client_secret: string}
     */
    private function credentials(string $companyId, string $connectionRef): array
    {
        $credentials = $this->marketplaceFacade->getConnectionCredentials(
            $companyId,
            MarketplaceType::OZON,
            MarketplaceConnectionType::PERFORMANCE,
            $connectionRef,
        );

        if (null === $credentials) {
            throw new ConnectorAuthException('Ozon Performance credentials were not found.');
        }

        $clientId = trim((string) ($credentials['client_id'] ?? ''));
        $clientSecret = trim((string) ($credentials['api_key'] ?? ''));
        if ('' === $clientId || '' === $clientSecret) {
            throw new ConnectorAuthException('Ozon Performance credentials are incomplete.');
        }

        return ['client_id' => $clientId, 'client_secret' => $clientSecret];
    }

    private function accessToken(string $companyId, string $connectionRef, string $clientId, string $clientSecret, bool $forceRefresh): string
    {
        $cacheKey = self::CACHE_KEY_TOKEN_PREFIX.hash('sha256', $companyId.'|'.$connectionRef);
        if ($forceRefresh) {
            $this->cache->delete($cacheKey);
        }

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($clientId, $clientSecret): string {
            try {
                $response = $this->httpClient->request('POST', $this->baseUrl.self::TOKEN_PATH, [
                    'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                    'json' => [
                        'client_id' => $clientId,
                        'client_secret' => $clientSecret,
                        'grant_type' => 'client_credentials',
                    ],
                    'timeout' => self::REQUEST_TIMEOUT,
                ]);
            } catch (TransportExceptionInterface $exception) {
                throw new ConnectorTransientException('Ozon Performance token transport error.', previous: $exception);
            }

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
            if (401 === $statusCode || 403 === $statusCode || 400 === $statusCode) {
                throw new ConnectorAuthException('Ozon Performance token request failed.');
            }
            if ($statusCode >= 500 || 429 === $statusCode) {
                throw new ConnectorTransientException(sprintf('Ozon Performance token request returned HTTP %d.', $statusCode));
            }
            if ($statusCode < 200 || $statusCode >= 300) {
                throw new \RuntimeException(sprintf('Ozon Performance token request returned HTTP %d.', $statusCode));
            }

            try {
                $payload = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new ConnectorTransientException('Ozon Performance token response is invalid JSON.', previous: $exception);
            }

            $token = is_array($payload) ? $this->stringField($payload['access_token'] ?? null) : '';
            if ('' === $token) {
                throw new ConnectorTransientException('Ozon Performance token response does not contain access_token.');
            }

            $expiresIn = isset($payload['expires_in']) && is_int($payload['expires_in']) ? $payload['expires_in'] : 1800;
            $item->expiresAfter(max(60, $expiresIn - self::TOKEN_TTL_SAFETY_MARGIN));

            return $token;
        });
    }

    private function classifyStatus(int $statusCode, string $endpoint, string $content): void
    {
        if (401 === $statusCode || 403 === $statusCode) {
            throw new ConnectorAuthException(sprintf('Ozon Performance auth failed for %s.', $endpoint));
        }

        if (429 === $statusCode) {
            throw new ConnectorRateLimitedException(sprintf('Ozon Performance rate limit for %s.', $endpoint), 120);
        }

        if ($statusCode >= 500) {
            throw new ConnectorTransientException(sprintf('Ozon Performance server error %d for %s.', $statusCode, $endpoint));
        }

        if (
            404 === $statusCode
            && preg_match('#^/api/client/campaign/([^/]+)/objects$#', $endpoint, $matches)
            && str_contains(strtolower($content), 'campaign not found')
        ) {
            throw new OzonPerformanceCampaignNotFoundException(
                rawurldecode($matches[1]),
                $endpoint,
                mb_substr($content, 0, 500),
            );
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(sprintf('Ozon Performance returned HTTP %d for %s: %s', $statusCode, $endpoint, mb_substr($content, 0, 500)));
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    private function extractRows(array $payload): array
    {
        foreach ([$payload['result'] ?? null, $payload['data'] ?? null, $payload] as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            if (array_is_list($candidate)) {
                return $this->objectRows($candidate);
            }

            foreach (['list', 'items', 'rows', 'campaigns', 'products', 'orders', 'statistics', 'expenses', 'data'] as $key) {
                $rows = $candidate[$key] ?? null;
                if (is_array($rows) && array_is_list($rows)) {
                    return $this->objectRows($rows);
                }
            }
        }

        return [];
    }

    /**
     * @param list<mixed> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function objectRows(array $rows): array
    {
        return array_values(array_filter($rows, static fn (mixed $row): bool => is_array($row) && !array_is_list($row)));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>       $metadata
     *
     * @return list<array<string, mixed>>
     */
    private function rowsWithMetadata(array $rows, array $metadata): array
    {
        return $this->sortRows(array_map(static fn (array $row): array => $row + ['_ingestion_metadata' => $metadata], $rows));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function sortRows(array $rows): array
    {
        if (count($rows) < 2) {
            return array_values($rows);
        }

        $serialized = [];
        foreach ($rows as $index => $row) {
            $serialized[$index] = (string) json_encode($row, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        }

        uksort($rows, static fn (int|string $left, int|string $right): int => $serialized[$left] <=> $serialized[$right]);

        return array_values($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodeReportContent(string $content): array
    {
        try {
            $payload = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
            if (is_array($payload)) {
                return $this->extractRows($payload);
            }
        } catch (\JsonException) {
        }

        if ($this->isZipContent($content)) {
            return $this->decodeZip($content);
        }

        return $this->decodeCsv($content);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodeZip(string $content): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new ConnectorTransientException('Ozon Performance ZIP report cannot be read because ext-zip is unavailable.');
        }

        $file = tempnam(sys_get_temp_dir(), 'ozon_perf_report_');
        if (false === $file) {
            throw new ConnectorTransientException('Ozon Performance ZIP report temporary file cannot be created.');
        }

        try {
            if (false === file_put_contents($file, $content)) {
                throw new ConnectorTransientException('Ozon Performance ZIP report temporary file cannot be written.');
            }

            $zip = new \ZipArchive();
            if (true !== $zip->open($file)) {
                throw new ConnectorTransientException('Ozon Performance ZIP report cannot be opened.');
            }

            try {
                $rows = [];
                $csvFiles = 0;
                for ($index = 0; $index < $zip->numFiles; ++$index) {
                    $name = (string) ($zip->getNameIndex($index) ?: '');
                    if ('' === $name || str_ends_with($name, '/')) {
                        continue;
                    }

                    $extension = strtolower(pathinfo($name, \PATHINFO_EXTENSION));
                    if (!in_array($extension, ['csv', 'txt'], true)) {
                        continue;
                    }

                    $csv = $zip->getFromIndex($index);
                    if (!is_string($csv)) {
                        continue;
                    }

                    ++$csvFiles;
                    array_push($rows, ...$this->decodeCsv($csv));
                }

                if (0 === $csvFiles) {
                    throw new ConnectorTransientException('Ozon Performance ZIP report does not contain CSV files.');
                }

                return $rows;
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($file);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodeCsv(string $content): array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $stream = fopen('php://memory', 'r+');
        if (false === $stream) {
            return [];
        }

        try {
            if (false === fwrite($stream, $content)) {
                return [];
            }
            rewind($stream);

            $firstLine = fgets($stream);
            if (false === $firstLine) {
                return [];
            }
            $separator = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
            rewind($stream);

            $header = fgetcsv($stream, 0, $separator, '"', '');
            if (false === $header) {
                return [];
            }

            $rows = [];
            while (false !== ($values = fgetcsv($stream, 0, $separator, '"', ''))) {
                if ([null] === $values || [''] === $values) {
                    continue;
                }

                $row = [];
                foreach ($header as $index => $name) {
                    if ('' === (string) $name) {
                        continue;
                    }

                    $row[(string) $name] = $values[$index] ?? null;
                }
                if ([] !== $row) {
                    $rows[] = $row;
                }
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param list<string> $campaignIds
     *
     * @return non-empty-list<string>
     */
    private function normalizeCampaignIds(array $campaignIds): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(static fn (mixed $id): string => trim((string) $id), $campaignIds))));
        if ([] === $normalized) {
            throw new \InvalidArgumentException('Ozon Performance request requires at least one campaign id.');
        }
        if (count($normalized) > 10) {
            throw new \InvalidArgumentException('Ozon Performance request supports at most 10 campaign ids.');
        }

        return $normalized;
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<string>
     */
    private function normalizeStringList(array $values): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(static fn (mixed $value): string => trim((string) $value), $values))));
        sort($normalized);

        return $normalized;
    }

    /**
     * @param list<string> $advObjectTypes
     */
    private function campaignCacheKey(string $companyId, string $connectionRef, array $advObjectTypes): string
    {
        return self::CACHE_KEY_CAMPAIGNS_PREFIX.hash('sha256', implode('|', [
            $companyId,
            $connectionRef,
            implode(',', $advObjectTypes),
        ]));
    }

    private function hasMore(array $payload, int $page, int $rowCount, int $pageSize): bool
    {
        $result = is_array($payload['result'] ?? null) ? $payload['result'] : $payload;
        if ((bool) ($result['has_next'] ?? $result['has_more'] ?? $payload['has_next'] ?? $payload['has_more'] ?? false)) {
            return true;
        }

        $total = $this->intValue($result['total'] ?? $payload['total'] ?? null);

        return $total > 0 && (($page - 1) * $pageSize + $rowCount) < $total;
    }

    private function intValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return 0;
    }

    private function stringField(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function normalizeReportUrl(string $url): string
    {
        $url = trim($url);
        if ('' === $url) {
            return $url;
        }

        if (null !== parse_url($url, \PHP_URL_SCHEME)) {
            return $url;
        }

        return $this->baseUrl.'/'.ltrim($url, '/');
    }

    private function isExternalUrl(string $url): bool
    {
        $baseHost = parse_url($this->baseUrl, \PHP_URL_HOST);
        $urlHost = parse_url($url, \PHP_URL_HOST);

        return is_string($urlHost) && '' !== $urlHost && $urlHost !== $baseHost;
    }

    private function isZipContent(string $content): bool
    {
        return str_starts_with($content, "PK\x03\x04")
            || str_starts_with($content, "PK\x05\x06")
            || str_starts_with($content, "PK\x07\x08");
    }

    private function startOfDayUtc(\DateTimeImmutable $date): string
    {
        return $date->setTime(0, 0)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private function endOfDayUtc(\DateTimeImmutable $date): string
    {
        return $date->setTime(23, 59, 59)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
