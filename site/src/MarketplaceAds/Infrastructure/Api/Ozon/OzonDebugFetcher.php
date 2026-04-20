<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Infrastructure\Api\Ozon;

use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Диагностический клиент Ozon Performance API для admin debug-страницы.
 *
 * Намеренно параллелен {@see OzonAdClient}, а не встроен в него: здесь нет
 * кэширования токена, ретраев, фильтра кампаний по state — каждый вызов
 * выполняется «честно» и возвращает сырой ответ Ozon, чтобы оператор мог
 * точечно воспроизводить 429/400 и сравнивать то, что уходит/приходит.
 *
 * Методы умышленно не скрывают HTTP-статусы и тела Ozon, чтобы debug-UI
 * мог их отрисовать как есть.
 */
final class OzonDebugFetcher
{
    private const BASE_URL = 'https://api-performance.ozon.ru';
    private const TOKEN_PATH = '/api/client/token';
    private const CAMPAIGN_PATH = '/api/client/campaign';
    private const STATISTICS_PATH = '/api/client/statistics';
    private const STATISTICS_STATE_PATH = '/api/client/statistics/%s';
    private const STATISTICS_REPORT_PATH = '/api/client/statistics/report';
    private const STATISTICS_LIST_PATH = '/api/client/statistics/list';

    private const REQUEST_TIMEOUT = 30;
    private const DOWNLOAD_TIMEOUT = 120;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly MarketplaceFacade $marketplaceFacade,
        #[Autowire(service: 'monolog.logger.marketplace_ads')]
        private readonly LoggerInterface $marketplaceAdsLogger,
    ) {
    }

    /**
     * @return array{
     *   access_token: string,
     *   expires_in: int,
     *   issued_at: string,
     *   ozon_raw_response_status: int,
     *   ozon_raw_body: array<string, mixed>,
     * }
     */
    public function fetchAccessToken(string $companyId): array
    {
        $credentials = $this->resolveCredentials($companyId);

        $this->marketplaceAdsLogger->info('Ozon debug: запрос токена', [
            'companyId' => $companyId,
        ]);

        try {
            $response = $this->httpClient->request('POST', self::BASE_URL.self::TOKEN_PATH, [
                'headers' => ['Content-Type' => 'application/json'],
                'json' => [
                    'client_id' => $credentials['client_id'],
                    'client_secret' => $credentials['client_secret'],
                    'grant_type' => 'client_credentials',
                ],
                'timeout' => self::REQUEST_TIMEOUT,
            ]);

            $statusCode = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException('Ozon Performance: сеть недоступна при получении токена: '.$e->getMessage(), 0, $e);
        }

        try {
            $rawBody = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException(sprintf(
                'Ozon Performance: обрыв соединения при чтении тела (HTTP %d, token): %s',
                $statusCode,
                $e->getMessage(),
            ), 0, $e);
        }

        $data = $this->decodeJsonSafe($rawBody);

        $accessToken = isset($data['access_token']) && is_string($data['access_token']) ? $data['access_token'] : '';
        $expiresIn = isset($data['expires_in']) && is_int($data['expires_in']) ? $data['expires_in'] : 0;

        return [
            'access_token' => $accessToken,
            'expires_in' => $expiresIn,
            'issued_at' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))->format(\DateTimeInterface::ATOM),
            'ozon_raw_response_status' => $statusCode,
            'ozon_raw_body' => $data,
        ];
    }

    /**
     * @return array{
     *   status_code: int,
     *   raw_body: array<string, mixed>,
     *   total: int,
     *   states_breakdown: array<string, int>,
     *   list: list<array<string, mixed>>,
     * }
     */
    public function listCampaigns(string $companyId): array
    {
        $token = $this->fetchAccessToken($companyId);
        $accessToken = $token['access_token'];
        if ('' === $accessToken) {
            throw new \RuntimeException('Ozon Performance: access_token пустой в ответе, debug-list-campaigns прерван');
        }

        $this->marketplaceAdsLogger->info('Ozon debug: запрос списка кампаний', [
            'companyId' => $companyId,
        ]);

        try {
            $response = $this->httpClient->request('GET', self::BASE_URL.self::CAMPAIGN_PATH, [
                'headers' => [
                    'Authorization' => 'Bearer '.$accessToken,
                    'Content-Type' => 'application/json',
                ],
                'timeout' => self::REQUEST_TIMEOUT,
            ]);

            $statusCode = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException('Ozon Performance: сеть недоступна (GET /campaign): '.$e->getMessage(), 0, $e);
        }

        try {
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException(sprintf(
                'Ozon Performance: обрыв соединения при чтении тела (HTTP %d, GET /campaign): %s',
                $statusCode,
                $e->getMessage(),
            ), 0, $e);
        }

        $data = $this->decodeJsonSafe($body);

        $list = is_array($data['list'] ?? null) ? array_values($data['list']) : [];
        $statesBreakdown = [];
        foreach ($list as $campaign) {
            if (!is_array($campaign)) {
                continue;
            }
            $state = isset($campaign['state']) ? (string) $campaign['state'] : '';
            $key = '' === $state ? '(empty)' : $state;
            $statesBreakdown[$key] = ($statesBreakdown[$key] ?? 0) + 1;
        }

        return [
            'status_code' => $statusCode,
            'raw_body' => $data,
            'total' => count($list),
            'states_breakdown' => $statesBreakdown,
            'list' => $list,
        ];
    }

    /**
     * Инвентаризация всех заказанных отчётов Ozon Performance на аккаунте.
     * Даёт видимость «висящих» UUID'ов (NOT_STARTED/IN_PROGRESS), которые
     * не видны в нашей БД, но продолжают занимать слоты очереди Ozon.
     *
     * @return array{
     *   status_code: int,
     *   items: list<array<string, mixed>>,
     *   total: int,
     *   states_breakdown: array<string, int>,
     *   raw_body: array<string, mixed>,
     * }
     */
    public function listReports(string $companyId, int $page = 1, int $pageSize = 50): array
    {
        if ($page < 1) {
            throw new \InvalidArgumentException('page: должно быть >= 1');
        }
        if ($pageSize < 1 || $pageSize > 1000) {
            throw new \InvalidArgumentException('pageSize: допустимо от 1 до 1000');
        }

        $token = $this->fetchAccessToken($companyId);
        $accessToken = $token['access_token'];
        if ('' === $accessToken) {
            throw new \RuntimeException('Ozon Performance: access_token пустой в ответе, debug-list-reports прерван');
        }

        $this->marketplaceAdsLogger->info('Ozon debug: GET /statistics/list', [
            'companyId' => $companyId,
            'page' => $page,
            'pageSize' => $pageSize,
        ]);

        try {
            $response = $this->httpClient->request('GET', self::BASE_URL.self::STATISTICS_LIST_PATH, [
                'headers' => [
                    'Authorization' => 'Bearer '.$accessToken,
                    'Content-Type' => 'application/json',
                ],
                'query' => [
                    'page' => $page,
                    'pageSize' => $pageSize,
                ],
                'timeout' => self::REQUEST_TIMEOUT,
            ]);

            $statusCode = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException('Ozon Performance: сеть недоступна (GET /statistics/list): '.$e->getMessage(), 0, $e);
        }

        try {
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException(sprintf(
                'Ozon Performance: обрыв соединения при чтении тела (HTTP %d, GET /statistics/list): %s',
                $statusCode,
                $e->getMessage(),
            ), 0, $e);
        }

        $data = $this->decodeJsonSafe($body);

        $items = [];
        foreach (['items', 'list', 'reports'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $items = array_values($data[$key]);
                break;
            }
        }

        $statesBreakdown = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $state = isset($item['state']) && is_scalar($item['state']) ? (string) $item['state'] : '';
            $key = '' === $state ? '(empty)' : $state;
            $statesBreakdown[$key] = ($statesBreakdown[$key] ?? 0) + 1;
        }

        return [
            'status_code' => $statusCode,
            'items' => $items,
            'total' => count($items),
            'states_breakdown' => $statesBreakdown,
            'raw_body' => $data,
        ];
    }

    /**
     * @param list<string> $campaignIds
     *
     * @return array{
     *   request_body: array<string, mixed>,
     *   uuid: string,
     *   ozon_status_code: int,
     *   ozon_raw_response: array<string, mixed>,
     * }
     */
    public function requestStatistics(
        string $companyId,
        array $campaignIds,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        string $groupBy,
    ): array {
        if ([] === $campaignIds) {
            throw new \InvalidArgumentException('campaigns: массив не может быть пустым');
        }
        if (count($campaignIds) > 10) {
            throw new \InvalidArgumentException('campaigns: максимум 10 ID в одном запросе');
        }
        if (!in_array($groupBy, ['NO_GROUP_BY', 'DATE'], true)) {
            throw new \InvalidArgumentException('groupBy: допустимы только NO_GROUP_BY или DATE');
        }
        if ($dateFrom > $dateTo) {
            throw new \InvalidArgumentException('from больше to');
        }

        $token = $this->fetchAccessToken($companyId);
        $accessToken = $token['access_token'];
        if ('' === $accessToken) {
            throw new \RuntimeException('Ozon Performance: access_token пустой в ответе');
        }

        $utc = new \DateTimeZone('UTC');
        $from = $dateFrom->setTime(0, 0, 0)->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
        $to = $dateTo->setTime(23, 59, 59)->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');

        $requestBody = [
            'campaigns' => array_values($campaignIds),
            'from' => $from,
            'to' => $to,
            'groupBy' => $groupBy,
        ];

        $this->marketplaceAdsLogger->info('Ozon debug: POST /statistics', [
            'companyId' => $companyId,
            'request_body' => $requestBody,
        ]);

        try {
            $response = $this->httpClient->request('POST', self::BASE_URL.self::STATISTICS_PATH, [
                'headers' => [
                    'Authorization' => 'Bearer '.$accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $requestBody,
                'timeout' => self::REQUEST_TIMEOUT,
            ]);

            $statusCode = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException('Ozon Performance: сеть недоступна (POST /statistics): '.$e->getMessage(), 0, $e);
        }

        try {
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException(sprintf(
                'Ozon Performance: обрыв соединения при чтении тела (HTTP %d, POST /statistics): %s',
                $statusCode,
                $e->getMessage(),
            ), 0, $e);
        }

        $data = $this->decodeJsonSafe($body);
        $uuid = '';
        if (isset($data['UUID']) && is_scalar($data['UUID'])) {
            $uuid = (string) $data['UUID'];
        } elseif (isset($data['uuid']) && is_scalar($data['uuid'])) {
            $uuid = (string) $data['uuid'];
        }

        return [
            'request_body' => $requestBody,
            'uuid' => $uuid,
            'ozon_status_code' => $statusCode,
            'ozon_raw_response' => $data,
        ];
    }

    /**
     * @return array{
     *   uuid: string,
     *   state: string,
     *   status_code: int,
     *   ozon_raw_response: array<string, mixed>,
     * }
     */
    public function checkStatus(string $companyId, string $uuid): array
    {
        if ('' === trim($uuid)) {
            throw new \InvalidArgumentException('uuid: не может быть пустым');
        }

        $token = $this->fetchAccessToken($companyId);
        $accessToken = $token['access_token'];
        if ('' === $accessToken) {
            throw new \RuntimeException('Ozon Performance: access_token пустой в ответе');
        }

        $this->marketplaceAdsLogger->info('Ozon debug: GET /statistics/{uuid}', [
            'companyId' => $companyId,
            'uuid' => $uuid,
        ]);

        try {
            $response = $this->httpClient->request(
                'GET',
                self::BASE_URL.sprintf(self::STATISTICS_STATE_PATH, rawurlencode($uuid)),
                [
                    'headers' => [
                        'Authorization' => 'Bearer '.$accessToken,
                        'Content-Type' => 'application/json',
                    ],
                    'timeout' => self::REQUEST_TIMEOUT,
                ],
            );

            $statusCode = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException('Ozon Performance: сеть недоступна (GET /statistics/{uuid}): '.$e->getMessage(), 0, $e);
        }

        try {
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException(sprintf(
                'Ozon Performance: обрыв соединения при чтении тела (HTTP %d, GET /statistics/{uuid}): %s',
                $statusCode,
                $e->getMessage(),
            ), 0, $e);
        }

        $data = $this->decodeJsonSafe($body);
        $state = isset($data['state']) && is_scalar($data['state']) ? (string) $data['state'] : '';

        return [
            'uuid' => $uuid,
            'state' => $state,
            'status_code' => $statusCode,
            'ozon_raw_response' => $data,
        ];
    }

    /**
     * @return array{
     *   was_zip: bool,
     *   size_bytes: int,
     *   content_preview: string,
     *   files_in_zip: list<array{name: string, size: int}>,
     *   raw_bytes: string,
     * }
     */
    public function downloadReport(string $companyId, string $uuid): array
    {
        if ('' === trim($uuid)) {
            throw new \InvalidArgumentException('uuid: не может быть пустым');
        }

        $token = $this->fetchAccessToken($companyId);
        $accessToken = $token['access_token'];
        if ('' === $accessToken) {
            throw new \RuntimeException('Ozon Performance: access_token пустой в ответе');
        }

        // Сначала спросим /statistics/{uuid}, чтобы взять link если он есть;
        // иначе fallback на STATISTICS_REPORT_PATH?UUID=.
        $status = $this->checkStatus($companyId, $uuid);
        $link = '';
        $rawResponse = $status['ozon_raw_response'];
        if (isset($rawResponse['link']) && is_scalar($rawResponse['link'])) {
            $link = (string) $rawResponse['link'];
        } elseif (isset($rawResponse['report']['link']) && is_scalar($rawResponse['report']['link'])) {
            $link = (string) $rawResponse['report']['link'];
        }
        if ('' === $link) {
            $link = self::STATISTICS_REPORT_PATH.'?UUID='.rawurlencode($uuid);
        }

        $url = str_starts_with($link, 'http://') || str_starts_with($link, 'https://')
            ? $link
            : self::BASE_URL.$link;

        $this->marketplaceAdsLogger->info('Ozon debug: download report', [
            'companyId' => $companyId,
            'uuid' => $uuid,
            'url' => $url,
        ]);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Authorization' => 'Bearer '.$accessToken,
                ],
                'timeout' => self::DOWNLOAD_TIMEOUT,
            ]);

            $statusCode = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException('Ozon Performance: сеть недоступна (download): '.$e->getMessage(), 0, $e);
        }

        try {
            $rawBytes = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException(sprintf(
                'Ozon Performance: обрыв соединения при чтении тела (HTTP %d, download): %s',
                $statusCode,
                $e->getMessage(),
            ), 0, $e);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(sprintf(
                'Ozon Performance: download вернул HTTP %d, body=%s',
                $statusCode,
                mb_strimwidth($rawBytes, 0, 500, '...'),
            ));
        }

        $wasZip = strlen($rawBytes) >= 4 && "PK\x03\x04" === substr($rawBytes, 0, 4);

        if ($wasZip) {
            $extracted = $this->extractZip($rawBytes);
            $preview = mb_strimwidth($extracted['firstCsv'], 0, 500, '...');

            return [
                'was_zip' => true,
                'size_bytes' => strlen($rawBytes),
                'content_preview' => $preview,
                'files_in_zip' => $extracted['files'],
                'raw_bytes' => $rawBytes,
            ];
        }

        return [
            'was_zip' => false,
            'size_bytes' => strlen($rawBytes),
            'content_preview' => mb_strimwidth($rawBytes, 0, 2000, '...'),
            'files_in_zip' => [],
            'raw_bytes' => $rawBytes,
        ];
    }

    /**
     * @return array{api_key: string, client_id: string}|null
     */
    public function getCredentials(string $companyId): ?array
    {
        $credentials = $this->marketplaceFacade->getConnectionCredentials(
            $companyId,
            MarketplaceType::OZON,
            MarketplaceConnectionType::PERFORMANCE,
        );

        if (null === $credentials) {
            return null;
        }

        $clientId = (string) ($credentials['client_id'] ?? '');
        $apiKey = (string) ($credentials['api_key'] ?? '');
        if ('' === $clientId || '' === $apiKey) {
            return null;
        }

        return ['api_key' => $apiKey, 'client_id' => $clientId];
    }

    /**
     * @return array{client_id: string, client_secret: string}
     */
    private function resolveCredentials(string $companyId): array
    {
        $credentials = $this->getCredentials($companyId);
        if (null === $credentials) {
            throw new OzonPermanentApiException('Ozon Performance не подключен или credentials пустые');
        }

        return [
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['api_key'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonSafe(string $body): array
    {
        if ('' === trim($body)) {
            return [];
        }
        try {
            $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['_raw' => mb_strimwidth($body, 0, 2000, '...'), '_invalid_json' => true];
        }
        if (!is_array($decoded)) {
            return ['_raw' => $body, '_invalid_json' => false];
        }

        return $decoded;
    }

    /**
     * @return array{files: list<array{name: string, size: int}>, firstCsv: string}
     */
    private function extractZip(string $zipBytes): array
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'ozon-debug-zip-');
        if (false === $tmpPath) {
            throw new \RuntimeException('Ozon debug: не удалось создать временный файл для ZIP');
        }

        try {
            if (false === file_put_contents($tmpPath, $zipBytes)) {
                throw new \RuntimeException('Ozon debug: не удалось записать ZIP во временный файл');
            }

            $zip = new \ZipArchive();
            $opened = $zip->open($tmpPath);
            if (true !== $opened) {
                throw new \RuntimeException(sprintf(
                    'Ozon debug: не удалось открыть ZIP (error code=%d)',
                    is_int($opened) ? $opened : -1,
                ));
            }

            try {
                $files = [];
                $firstCsv = '';
                for ($i = 0; $i < $zip->numFiles; ++$i) {
                    $name = $zip->getNameIndex($i);
                    if (false === $name) {
                        continue;
                    }
                    $stat = $zip->statIndex($i);
                    $size = is_array($stat) && isset($stat['size']) ? (int) $stat['size'] : 0;
                    $files[] = ['name' => $name, 'size' => $size];

                    if ('' === $firstCsv && str_ends_with(strtolower($name), '.csv')) {
                        $content = $zip->getFromIndex($i);
                        if (false !== $content) {
                            $firstCsv = $content;
                        }
                    }
                }
            } finally {
                $zip->close();
            }

            return ['files' => $files, 'firstCsv' => $firstCsv];
        } finally {
            @unlink($tmpPath);
        }
    }
}
