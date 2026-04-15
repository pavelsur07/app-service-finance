<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Infrastructure\Api\Ozon;

use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAds\Infrastructure\Api\Contract\AdPlatformClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Клиент Ozon Performance API: загружает суточную рекламную статистику в формате,
 * совместимом с {@see OzonAdRawDataParser}.
 *
 * Жизненный цикл одного fetch:
 *   1) get OAuth-token (cache: ozon_perf_token_{companyId}, TTL = expires_in - 300);
 *   2) GET  /api/client/campaign — список активных SKU-кампаний;
 *   3) POST /api/client/statistics батчами по 10 → UUID отчёта;
 *   4) GET  /api/client/statistics/{uuid} — polling до READY (макс. 36 попыток × 5с = 3 мин);
 *   5) GET  /api/client/statistics/report — скачать CSV (либо сразу из state.report.link);
 *   6) преобразовать строки CSV в формат {"rows": [{campaign_id, campaign_name, sku, spend, views, clicks}]}.
 *
 * 401 на любом запросе после получения токена → сбрасываем кэш и пробуем один раз
 * заново (актуально, если кто-то параллельно отозвал токен в ЛК Ozon).
 */
final class OzonAdClient implements AdPlatformClientInterface
{
    private const BASE_URL = 'https://api-performance.ozon.ru';
    private const TOKEN_PATH = '/api/client/token';
    private const CAMPAIGN_PATH = '/api/client/campaign';
    private const STATISTICS_PATH = '/api/client/statistics';
    private const STATISTICS_STATE_PATH = '/api/client/statistics/%s';
    private const STATISTICS_REPORT_PATH = '/api/client/statistics/report';

    private const REQUEST_TIMEOUT = 30;
    private const TOKEN_TTL_SAFETY_MARGIN = 300;
    private const STATISTICS_BATCH_SIZE = 10;
    private const POLL_MAX_ATTEMPTS = 36;
    private const POLL_INTERVAL_SECONDS = 5;
    private const CACHE_KEY_TOKEN_PREFIX = 'ozon_perf_token_';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly MarketplaceFacade $marketplaceFacade,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(string $marketplace): bool
    {
        return $marketplace === MarketplaceType::OZON->value;
    }

    public function getRequiredConnectionType(): MarketplaceConnectionType
    {
        return MarketplaceConnectionType::PERFORMANCE;
    }

    public function fetchAdStatistics(string $companyId, \DateTimeImmutable $date): string
    {
        $credentials = $this->marketplaceFacade->getConnectionCredentials(
            $companyId,
            MarketplaceType::OZON,
            MarketplaceConnectionType::PERFORMANCE,
        );

        if (null === $credentials) {
            throw new \RuntimeException('Ozon Performance не подключен');
        }

        $clientId = (string) ($credentials['client_id'] ?? '');
        $clientSecret = (string) ($credentials['api_key'] ?? '');
        if ('' === $clientId || '' === $clientSecret) {
            throw new \RuntimeException('Ozon Performance: отсутствует client_id или client_secret');
        }

        $this->logger->info('Ozon Performance: начало загрузки статистики', [
            'companyId' => $companyId,
            'date' => $date->format('Y-m-d'),
        ]);

        $campaigns = $this->withAuthRetry(
            $companyId,
            $clientId,
            $clientSecret,
            fn (string $token): array => $this->listActiveSkuCampaigns($token),
        );

        $this->logger->info('Ozon Performance: получены активные SKU-кампании', [
            'companyId' => $companyId,
            'count' => count($campaigns),
        ]);

        if ([] === $campaigns) {
            return '{"rows": []}';
        }

        $rows = [];
        foreach (array_chunk($campaigns, self::STATISTICS_BATCH_SIZE) as $batch) {
            $campaignIds = array_map(static fn (array $c): string => (string) $c['id'], $batch);
            $namesById = [];
            foreach ($batch as $campaign) {
                $namesById[(string) $campaign['id']] = (string) ($campaign['title'] ?? $campaign['name'] ?? '');
            }

            $uuid = $this->withAuthRetry(
                $companyId,
                $clientId,
                $clientSecret,
                fn (string $token): string => $this->requestStatistics($token, $campaignIds, $date),
            );

            $this->logger->info('Ozon Performance: запрошен отчёт', [
                'companyId' => $companyId,
                'reportUuid' => $uuid,
                'campaignCount' => count($campaignIds),
            ]);

            $reportLink = $this->withAuthRetry(
                $companyId,
                $clientId,
                $clientSecret,
                fn (string $token): string => $this->pollReport($token, $uuid),
            );

            $csv = $this->withAuthRetry(
                $companyId,
                $clientId,
                $clientSecret,
                fn (string $token): string => $this->downloadReport($token, $reportLink),
            );

            foreach ($this->convertCsvToRows($csv, $namesById) as $row) {
                $rows[] = $row;
            }
        }

        return json_encode(['rows' => $rows], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * Выполняет колбэк с актуальным токеном; при 401 один раз сбрасывает кэш и повторяет.
     *
     * @template T
     *
     * @param callable(string): T $callback
     *
     * @return T
     */
    private function withAuthRetry(
        string $companyId,
        string $clientId,
        string $clientSecret,
        callable $callback,
    ): mixed {
        $token = $this->getAccessToken($companyId, $clientId, $clientSecret, forceRefresh: false);

        try {
            return $callback($token);
        } catch (OzonAuthExpiredException) {
            $this->logger->info('Ozon Performance: токен отклонён (401), повторяю с новым', [
                'companyId' => $companyId,
            ]);
            $token = $this->getAccessToken($companyId, $clientId, $clientSecret, forceRefresh: true);

            return $callback($token);
        }
    }

    private function getAccessToken(
        string $companyId,
        string $clientId,
        string $clientSecret,
        bool $forceRefresh,
    ): string {
        $cacheKey = self::CACHE_KEY_TOKEN_PREFIX.$companyId;
        if ($forceRefresh) {
            $this->cache->delete($cacheKey);
        }

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($clientId, $clientSecret): string {
            try {
                $response = $this->httpClient->request('POST', self::BASE_URL.self::TOKEN_PATH, [
                    'headers' => ['Content-Type' => 'application/json'],
                    'json' => [
                        'client_id' => $clientId,
                        'client_secret' => $clientSecret,
                        'grant_type' => 'client_credentials',
                    ],
                    'timeout' => self::REQUEST_TIMEOUT,
                ]);

                $statusCode = $response->getStatusCode();
            } catch (TransportExceptionInterface $e) {
                throw new \RuntimeException('Ozon Performance: сеть недоступна при получении токена', 0, $e);
            }

            if (200 !== $statusCode) {
                throw new \RuntimeException(sprintf('Ozon Performance: получение токена вернуло HTTP %d', $statusCode));
            }

            try {
                $data = $response->toArray(false);
            } catch (\Throwable $e) {
                throw new \RuntimeException('Ozon Performance: некорректный ответ при получении токена', 0, $e);
            }

            $token = isset($data['access_token']) && is_string($data['access_token']) ? $data['access_token'] : '';
            if ('' === $token) {
                throw new \RuntimeException('Ozon Performance: ответ не содержит access_token');
            }

            $expiresIn = isset($data['expires_in']) && is_int($data['expires_in']) ? $data['expires_in'] : 1800;
            $ttl = max(60, $expiresIn - self::TOKEN_TTL_SAFETY_MARGIN);
            $item->expiresAfter($ttl);

            return $token;
        });
    }

    /**
     * @return list<array{id: string, title: string}>
     */
    private function listActiveSkuCampaigns(string $token): array
    {
        $response = $this->authorizedRequest('GET', self::CAMPAIGN_PATH, $token);
        $data = $this->decodeJson($response->getContent(false), 'campaign list');

        $list = $data['list'] ?? [];
        if (!is_array($list)) {
            return [];
        }

        $result = [];
        foreach ($list as $campaign) {
            if (!is_array($campaign)) {
                continue;
            }
            $state = (string) ($campaign['state'] ?? '');
            $advType = (string) ($campaign['advObjectType'] ?? '');
            if ('CAMPAIGN_STATE_RUNNING' !== $state || 'SKU' !== $advType) {
                continue;
            }
            $id = isset($campaign['id']) ? (string) $campaign['id'] : '';
            if ('' === $id) {
                continue;
            }
            $result[] = [
                'id' => $id,
                'title' => (string) ($campaign['title'] ?? ''),
            ];
        }

        return $result;
    }

    /**
     * @param list<string> $campaignIds
     */
    private function requestStatistics(string $token, array $campaignIds, \DateTimeImmutable $date): string
    {
        $dateStr = $date->format('Y-m-d');
        $response = $this->authorizedRequest('POST', self::STATISTICS_PATH, $token, [
            'json' => [
                'campaigns' => $campaignIds,
                'from' => $dateStr,
                'to' => $dateStr,
                'groupBy' => 'NO_GROUP_BY',
            ],
        ]);

        $data = $this->decodeJson($response->getContent(false), 'statistics request');

        $uuid = isset($data['UUID']) ? (string) $data['UUID'] : (string) ($data['uuid'] ?? '');
        if ('' === $uuid) {
            throw new \RuntimeException('Ozon Performance: ответ /statistics не содержит UUID');
        }

        return $uuid;
    }

    /**
     * @return string ссылка на готовый отчёт (CSV)
     */
    private function pollReport(string $token, string $uuid): string
    {
        $startedAt = microtime(true);

        for ($attempt = 1; $attempt <= self::POLL_MAX_ATTEMPTS; ++$attempt) {
            $response = $this->authorizedRequest(
                'GET',
                sprintf(self::STATISTICS_STATE_PATH, rawurlencode($uuid)),
                $token,
            );
            $data = $this->decodeJson($response->getContent(false), 'statistics state');

            $state = (string) ($data['state'] ?? '');
            if ('OK' === $state || 'READY' === $state) {
                $link = (string) ($data['link'] ?? ($data['report']['link'] ?? ''));
                if ('' === $link) {
                    // Старые версии API не отдают link отдельно — отчёт скачивается
                    // по фиксированному /report?UUID=…
                    $link = self::STATISTICS_REPORT_PATH.'?UUID='.rawurlencode($uuid);
                }

                $this->logger->info('Ozon Performance: отчёт готов', [
                    'reportUuid' => $uuid,
                    'attempts' => $attempt,
                    'waitedSeconds' => round(microtime(true) - $startedAt, 1),
                ]);

                return $link;
            }

            if ('ERROR' === $state || 'CANCELLED' === $state || 'NOT_FOUND' === $state) {
                throw new \RuntimeException(sprintf(
                    'Ozon Performance: отчёт %s завершился со статусом %s: %s',
                    $uuid,
                    $state,
                    (string) ($data['error'] ?? ''),
                ));
            }

            sleep(self::POLL_INTERVAL_SECONDS);
        }

        throw new \RuntimeException(sprintf(
            'Ozon Performance: отчёт %s не готов за %d секунд',
            $uuid,
            self::POLL_MAX_ATTEMPTS * self::POLL_INTERVAL_SECONDS,
        ));
    }

    private function downloadReport(string $token, string $reportLink): string
    {
        // link может прийти и абсолютным (https://...), и относительным (/api/...).
        $url = str_starts_with($reportLink, 'http://') || str_starts_with($reportLink, 'https://')
            ? $reportLink
            : self::BASE_URL.$reportLink;

        $response = $this->authorizedRequest('GET', $url, $token, [], absoluteUrl: true);

        return $response->getContent(false);
    }

    /**
     * Преобразует CSV отчёта Ozon Performance в плоский список строк формата
     * {campaign_id, campaign_name, sku, spend, views, clicks}.
     *
     * @param array<string, string> $namesById кэш campaign_name по campaign_id
     *
     * @return list<array{campaign_id: string, campaign_name: string, sku: string, spend: float, views: int, clicks: int}>
     */
    private function convertCsvToRows(string $csv, array $namesById): array
    {
        $csv = ltrim($csv, "\xEF\xBB\xBF"); // drop UTF-8 BOM
        if ('' === trim($csv)) {
            return [];
        }

        // Ozon отдаёт отчёт с разделителем ';' и заголовком в первой строке;
        // допускаем и ',' если поменяется формат — определяем по первой строке.
        $firstNewline = strpos($csv, "\n");
        $headerLine = false === $firstNewline ? $csv : substr($csv, 0, $firstNewline);
        $delimiter = (substr_count($headerLine, ';') >= substr_count($headerLine, ',')) ? ';' : ',';

        $lines = preg_split('/\r\n|\r|\n/', $csv) ?: [];
        $header = null;
        $rows = [];

        foreach ($lines as $line) {
            if ('' === trim($line)) {
                continue;
            }
            $cols = str_getcsv($line, $delimiter, '"', '\\');
            if (null === $header) {
                $header = array_map(static fn ($c): string => strtolower(trim((string) $c)), $cols);
                continue;
            }

            $row = [];
            foreach ($header as $i => $name) {
                $row[$name] = $cols[$i] ?? '';
            }

            $campaignId = (string) ($row['campaign_id'] ?? $row['id'] ?? '');
            $sku = (string) ($row['sku'] ?? $row['ozon_sku'] ?? '');
            if ('' === $campaignId || '' === $sku) {
                continue;
            }

            $rows[] = [
                'campaign_id' => $campaignId,
                'campaign_name' => (string) ($row['campaign_name'] ?? $namesById[$campaignId] ?? ''),
                'sku' => $sku,
                'spend' => (float) str_replace(',', '.', (string) ($row['spend'] ?? $row['cost'] ?? '0')),
                'views' => (int) ($row['views'] ?? $row['impressions'] ?? 0),
                'clicks' => (int) ($row['clicks'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function authorizedRequest(
        string $method,
        string $urlOrPath,
        string $token,
        array $options = [],
        bool $absoluteUrl = false,
    ): \Symfony\Contracts\HttpClient\ResponseInterface {
        $url = $absoluteUrl ? $urlOrPath : self::BASE_URL.$urlOrPath;
        $options = array_replace_recursive([
            'headers' => [
                'Authorization' => 'Bearer '.$token,
                'Content-Type' => 'application/json',
            ],
            'timeout' => self::REQUEST_TIMEOUT,
        ], $options);

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $statusCode = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException(sprintf('Ozon Performance: сеть недоступна (%s %s)', $method, $urlOrPath), 0, $e);
        }

        if (401 === $statusCode || 403 === $statusCode) {
            throw new OzonAuthExpiredException();
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(sprintf(
                'Ozon Performance: %s %s вернул HTTP %d',
                $method,
                $urlOrPath,
                $statusCode,
            ));
        }

        return $response;
    }

    /**
     * @return array<mixed>
     */
    private function decodeJson(string $body, string $context): array
    {
        try {
            /** @var array<mixed> $data */
            $data = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('Ozon Performance: невалидный JSON (%s)', $context), 0, $e);
        }

        if (!is_array($data)) {
            throw new \RuntimeException(sprintf('Ozon Performance: ожидался JSON-объект (%s)', $context));
        }

        return $data;
    }
}
