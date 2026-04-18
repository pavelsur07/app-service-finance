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
 *   2) GET  /api/client/campaign — все SKU-кампании (без фильтра по state, чтобы
 *      не терять backfill остановленных/архивных кампаний);
 *   3) POST /api/client/statistics батчами (до 100 campaignIds) → UUID отчёта;
 *   4) GET  /api/client/statistics/{uuid} — polling до READY (макс. 36 попыток × 5с = 3 мин);
 *   5) GET  /api/client/statistics/report — скачать CSV (либо сразу из state.report.link);
 *   6) преобразовать строки CSV в формат {"rows": [{campaign_id, campaign_name, sku, spend, views, clicks}]}.
 *
 * 401 на любом запросе после получения токена → сбрасываем кэш и пробуем один раз
 * заново (актуально, если кто-то параллельно отозвал токен в ЛК Ozon). 403 трактуется
 * как permanent denial (нет скоупа «Продвижение») и ретраится не будет.
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
    // Ozon Performance API принимает до 100 campaignIds в теле /statistics.
    // При блокирующем sleep-polling чем больше батч, тем меньше суммарное ожидание.
    private const STATISTICS_BATCH_SIZE = 100;
    private const POLL_MAX_ATTEMPTS = 36;
    private const POLL_INTERVAL_SECONDS = 5;
    private const CACHE_KEY_TOKEN_PREFIX = 'ozon_perf_token_';

    /**
     * Счётчик итераций последнего pollReport() — используется инструментированием
     * fetchAdStatisticsRange(), чтобы не ломать публичную сигнатуру pollReport
     * и не пробрасывать значения через withAuthRetry-замыкания.
     */
    private int $lastPollAttempts = 0;

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
        $credentials = $this->resolveCredentials($companyId);
        $clientId = $credentials['client_id'];
        $clientSecret = $credentials['client_secret'];

        $this->logger->info('Ozon Performance: начало загрузки статистики', [
            'companyId' => $companyId,
            'date' => $date->format('Y-m-d'),
        ]);

        $campaigns = $this->withAuthRetry(
            $companyId,
            $clientId,
            $clientSecret,
            fn (string $token): array => $this->listSkuCampaigns($token),
        );

        $this->logger->info('Ozon Performance: получен список SKU-кампаний', [
            'companyId' => $companyId,
            'count' => count($campaigns),
        ]);

        if ([] === $campaigns) {
            return '{"rows": []}';
        }

        $rows = [];
        foreach (array_chunk($campaigns, self::STATISTICS_BATCH_SIZE) as $batch) {
            [$campaignIds, $namesById] = $this->splitBatch($batch);

            $uuid = $this->withAuthRetry(
                $companyId,
                $clientId,
                $clientSecret,
                fn (string $token): string => $this->requestStatistics($token, $campaignIds, $date, $date, 'NO_GROUP_BY'),
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
     * Статистика Ozon Performance за диапазон дат, сгруппированная по дням.
     *
     * Один async-пайплайн на весь диапазон: листинг кампаний → POST /statistics с
     * groupBy=DATE → polling → скачивание CSV → группировка строк по дате.
     * Caller обязан передать диапазон ≤ 62 дня (лимит Ozon Performance API).
     *
     * @return array<string, array{campaigns: list<array{
     *     campaign_id: string,
     *     campaign_name: string,
     *     rows: list<array{
     *         sku: string,
     *         spend: string,
     *         views: int,
     *         clicks: int,
     *     }>,
     * }>}>
     *
     * @throws \InvalidArgumentException если dateFrom > dateTo или диапазон > 62 дня
     * @throws \RuntimeException         если Performance-подключение не настроено либо API вернул ошибку
     */
    public function fetchAdStatisticsRange(
        string $companyId,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
    ): array {
        $startedAt = microtime(true);
        // chunkDays считаем ещё до assertValidRange, чтобы лог ошибки содержал
        // фактический входной диапазон (даже если он > 62 дней).
        $chunkDays = (int) $dateFrom->diff($dateTo)->days + 1;
        $campaignsCount = 0;
        $rowsCount = 0;
        $totalPollAttempts = 0;

        try {
            $this->assertValidRange($dateFrom, $dateTo);

            $credentials = $this->resolveCredentials($companyId);
            $clientId = $credentials['client_id'];
            $clientSecret = $credentials['client_secret'];

            $this->logger->info('Ozon Performance: начало загрузки статистики (range)', [
                'companyId' => $companyId,
                'dateFrom' => $dateFrom->format('Y-m-d'),
                'dateTo' => $dateTo->format('Y-m-d'),
            ]);

            $campaigns = $this->withAuthRetry(
                $companyId,
                $clientId,
                $clientSecret,
                fn (string $token): array => $this->listSkuCampaigns($token),
            );
            $campaignsCount = count($campaigns);

            $this->logger->info('Ozon Performance: получен список SKU-кампаний (range)', [
                'companyId' => $companyId,
                'count' => $campaignsCount,
            ]);

            /** @var array<string, array<string, array{campaign_id: string, campaign_name: string, rows: list<array{sku: string, spend: string, views: int, clicks: int}>}>> $byDate */
            $byDate = [];

            foreach (array_chunk($campaigns, self::STATISTICS_BATCH_SIZE) as $batch) {
                [$campaignIds, $namesById] = $this->splitBatch($batch);

                $uuid = $this->withAuthRetry(
                    $companyId,
                    $clientId,
                    $clientSecret,
                    fn (string $token): string => $this->requestStatistics($token, $campaignIds, $dateFrom, $dateTo, 'DATE'),
                );

                $this->logger->info('Ozon Performance: запрошен отчёт (range)', [
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
                // pollReport не возвращает attempts, чтобы не ломать публичную
                // сигнатуру — счётчик читаем через $this->lastPollAttempts и
                // суммируем по всем батчам.
                $totalPollAttempts += $this->lastPollAttempts;

                $csv = $this->withAuthRetry(
                    $companyId,
                    $clientId,
                    $clientSecret,
                    fn (string $token): string => $this->downloadReport($token, $reportLink),
                );

                foreach ($this->convertCsvToRowsByDate($csv, $namesById) as $date => $campaignsForDate) {
                    // Батчи не пересекаются по campaign_id (array_chunk), поэтому коллизий
                    // внутри одной даты не будет — просто складываем кампании подряд.
                    foreach ($campaignsForDate as $campaignId => $campaign) {
                        $byDate[$date][$campaignId] = $campaign;
                        $rowsCount += count($campaign['rows']);
                    }
                }
            }

            ksort($byDate);

            $result = [];
            foreach ($byDate as $date => $campaignsMap) {
                $result[$date] = ['campaigns' => array_values($campaignsMap)];
            }

            $this->logger->info('Ozon ad statistics fetched', [
                'company_id' => $companyId,
                'date_from' => $dateFrom->format('Y-m-d'),
                'date_to' => $dateTo->format('Y-m-d'),
                'chunk_days' => $chunkDays,
                'campaigns_count' => $campaignsCount,
                'rows_count' => $rowsCount,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'poll_attempts' => $totalPollAttempts,
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Ozon ad statistics fetch failed', [
                'company_id' => $companyId,
                'date_from' => $dateFrom->format('Y-m-d'),
                'date_to' => $dateTo->format('Y-m-d'),
                'chunk_days' => $chunkDays,
                'campaigns_count' => $campaignsCount,
                'rows_count' => $rowsCount,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'poll_attempts' => $totalPollAttempts,
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @return array{client_id: string, client_secret: string}
     */
    private function resolveCredentials(string $companyId): array
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

        return ['client_id' => $clientId, 'client_secret' => $clientSecret];
    }

    private function assertValidRange(\DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): void
    {
        if ($dateFrom > $dateTo) {
            throw new \InvalidArgumentException(sprintf('Ozon Performance: dateFrom (%s) больше dateTo (%s)', $dateFrom->format('Y-m-d'), $dateTo->format('Y-m-d')));
        }

        // diff->days считает разницу в полных сутках; для одинаковых дат = 0.
        // Для контракта «до 62 дней включительно» переводим в инклюзивный счёт.
        $diff = $dateFrom->diff($dateTo);
        $inclusiveDays = (int) $diff->days + 1;
        if ($inclusiveDays > 62) {
            throw new \InvalidArgumentException(sprintf('Ozon Performance API supports max 62 days per request, got %d days', $inclusiveDays));
        }
    }

    /**
     * @param list<array{id: string, title: string}> $batch
     *
     * @return array{0: list<string>, 1: array<string, string>}
     */
    private function splitBatch(array $batch): array
    {
        $campaignIds = array_map(static fn (array $c): string => (string) $c['id'], $batch);
        $namesById = [];
        foreach ($batch as $campaign) {
            // title уже содержит либо title, либо name (см. listSkuCampaigns).
            $namesById[(string) $campaign['id']] = (string) $campaign['title'];
        }

        return [$campaignIds, $namesById];
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
     * Возвращает все SKU-кампании компании — включая остановленные, архивные
     * и неактивные. Фильтр по state = RUNNING специально снят: для backfill-а
     * за прошедшую дату нужны и кампании, которые сегодня уже остановлены,
     * но вчера откручивались. Кампании без активности на $date просто вернут
     * пустые строки из /statistics и отфильтруются дальше.
     *
     * @return list<array{id: string, title: string}>
     */
    private function listSkuCampaigns(string $token): array
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
            $advType = (string) ($campaign['advObjectType'] ?? '');
            if ('SKU' !== $advType) {
                continue;
            }
            $id = isset($campaign['id']) ? (string) $campaign['id'] : '';
            if ('' === $id) {
                continue;
            }
            // title / name — разные версии API отдают имя кампании в одном из двух полей.
            // Собираем оба сразу, чтобы не потерять имя в namesById ниже.
            $result[] = [
                'id' => $id,
                'title' => (string) ($campaign['title'] ?? $campaign['name'] ?? ''),
            ];
        }

        return $result;
    }

    /**
     * @param list<string>         $campaignIds
     * @param 'NO_GROUP_BY'|'DATE' $groupBy
     */
    private function requestStatistics(
        string $token,
        array $campaignIds,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        string $groupBy,
    ): string {
        $response = $this->authorizedRequest('POST', self::STATISTICS_PATH, $token, [
            'json' => [
                'campaigns' => $campaignIds,
                'from' => $dateFrom->format('Y-m-d'),
                'to' => $dateTo->format('Y-m-d'),
                'groupBy' => $groupBy,
            ],
        ]);

        $data = $this->decodeJson($response->getContent(false), 'statistics request');

        $uuid = $this->stringifyApiField($data['UUID'] ?? $data['uuid'] ?? null);
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
            $this->lastPollAttempts = $attempt;
            $response = $this->authorizedRequest(
                'GET',
                sprintf(self::STATISTICS_STATE_PATH, rawurlencode($uuid)),
                $token,
            );
            $data = $this->decodeJson($response->getContent(false), 'statistics state');

            $state = $this->stringifyApiField($data['state'] ?? null);
            if ('OK' === $state || 'READY' === $state) {
                $link = $this->stringifyApiField($data['link'] ?? $data['report']['link'] ?? null);
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
                    $this->stringifyApiField($data['error'] ?? null),
                ));
            }

            sleep(self::POLL_INTERVAL_SECONDS);
        }

        throw new \RuntimeException(sprintf('Ozon Performance: отчёт %s не готов за %d секунд', $uuid, self::POLL_MAX_ATTEMPTS * self::POLL_INTERVAL_SECONDS));
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
     * Преобразует CSV отчёта Ozon Performance (NO_GROUP_BY) в плоский список строк
     * формата {campaign_id, campaign_name, sku, spend, views, clicks}.
     *
     * @param array<string, string> $namesById кэш campaign_name по campaign_id
     *
     * @return list<array{campaign_id: string, campaign_name: string, sku: string, spend: float, views: int, clicks: int}>
     */
    private function convertCsvToRows(string $csv, array $namesById): array
    {
        $rows = [];
        foreach ($this->iterateCsvAssocRows($csv) as $row) {
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
     * Преобразует CSV отчёта Ozon Performance (groupBy=DATE) в структуру,
     * сгруппированную по дате:
     *   Y-m-d => [campaignId => {campaign_id, campaign_name, rows:[{sku, spend, views, clicks}, ...]}].
     *
     * Возвращает промежуточную map (не финальный shape с `array_values`), чтобы caller
     * мог мёрджить результаты нескольких батчей без лишних O(N) проходов.
     *
     * @param array<string, string> $namesById кэш campaign_name по campaign_id
     *
     * @return array<string, array<string, array{
     *     campaign_id: string,
     *     campaign_name: string,
     *     rows: list<array{sku: string, spend: string, views: int, clicks: int}>,
     * }>>
     *
     * @throws \RuntimeException если в строке отсутствует или невалидна колонка date
     */
    private function convertCsvToRowsByDate(string $csv, array $namesById): array
    {
        $result = [];
        foreach ($this->iterateCsvAssocRows($csv) as $row) {
            $campaignId = (string) ($row['campaign_id'] ?? $row['id'] ?? '');
            $sku = (string) ($row['sku'] ?? $row['ozon_sku'] ?? '');
            if ('' === $campaignId || '' === $sku) {
                // Пустые/частично заполненные строки пропускаем — как в convertCsvToRows.
                continue;
            }

            $rawDate = $this->findDateField($row);
            if ('' === $rawDate) {
                throw new \RuntimeException(sprintf('Ozon Performance: в строке отчёта отсутствует поле даты (ожидается одна из колонок: date, day, дата); campaign_id=%s, sku=%s', $campaignId, $sku));
            }
            $date = $this->parseDateField($rawDate);

            $campaignName = (string) ($row['campaign_name'] ?? $namesById[$campaignId] ?? '');

            if (!isset($result[$date][$campaignId])) {
                $result[$date][$campaignId] = [
                    'campaign_id' => $campaignId,
                    'campaign_name' => $campaignName,
                    'rows' => [],
                ];
            }

            $result[$date][$campaignId]['rows'][] = [
                'sku' => $sku,
                'spend' => $this->normalizeDecimal((string) ($row['spend'] ?? $row['cost'] ?? '0')),
                'views' => (int) ($row['views'] ?? $row['impressions'] ?? 0),
                'clicks' => (int) ($row['clicks'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Единая CSV-итерация: drop BOM, автодетект разделителя, stream через
     * in-memory FILE*, возврат строк с lowercased-заголовками.
     *
     * @return \Generator<int, array<string, string>>
     */
    private function iterateCsvAssocRows(string $csv): \Generator
    {
        $csv = ltrim($csv, "\xEF\xBB\xBF"); // drop UTF-8 BOM
        if ('' === trim($csv)) {
            return;
        }

        // Определяем разделитель по первой строке заголовка. Если в заголовке
        // нет ';' — считаем CSV стандартным RFC 4180 (разделитель ',').
        // Для 1-колоночного отчёта выбор делимитера всё равно не влияет на парсинг.
        $firstNewline = strpos($csv, "\n");
        $headerLine = false === $firstNewline ? $csv : substr($csv, 0, $firstNewline);
        $delimiter = str_contains($headerLine, ';') ? ';' : ',';

        // Стрим через in-memory FILE*: fgetcsv корректно обрабатывает значения
        // с переводами строк внутри кавычек и не создаёт отдельный массив строк
        // в памяти. escape='' — RFC 4180 не знает backslash-экранирования,
        // экранирование кавычки делается удвоением ("" внутри строкового поля).
        $fp = fopen('php://memory', 'r+b');
        if (false === $fp) {
            throw new \RuntimeException('Ozon Performance: не удалось открыть in-memory поток для CSV');
        }

        try {
            fwrite($fp, $csv);
            rewind($fp);

            $headerRow = fgetcsv($fp, 0, $delimiter, '"', '');
            if (false === $headerRow) {
                return;
            }
            // mb_strtolower, а не strtolower — иначе локализованные заголовки
            // (например, "Дата") останутся в исходном регистре и findDateField()
            // с ключом "дата" их не найдёт.
            $header = array_map(static fn ($c): string => mb_strtolower(trim((string) $c), 'UTF-8'), $headerRow);

            while (false !== ($cols = fgetcsv($fp, 0, $delimiter, '"', ''))) {
                // fgetcsv на полностью пустой строке возвращает [null] — пропускаем.
                if ([null] === $cols) {
                    continue;
                }

                $row = [];
                foreach ($header as $i => $name) {
                    $row[$name] = (string) ($cols[$i] ?? '');
                }

                yield $row;
            }
        } finally {
            fclose($fp);
        }
    }

    /**
     * @param array<string, string> $row
     */
    private function findDateField(array $row): string
    {
        foreach (['date', 'day', 'дата'] as $key) {
            if (isset($row[$key])) {
                $value = trim($row[$key]);
                if ('' !== $value) {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * Парсит дату из CSV: принимает YYYY-MM-DD или DD.MM.YYYY.
     * Нормализованный вывод — всегда Y-m-d.
     *
     * @throws \RuntimeException если формат не распознан либо дата некорректна
     */
    private function parseDateField(string $value): string
    {
        // createFromFormat нормализует несуществующие даты (31.02.2026 → 02.03.2026),
        // поэтому roundtrip-сравнением отсекаем такие случаи.
        $iso = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false !== $iso && $iso->format('Y-m-d') === $value) {
            return $value;
        }

        $dmy = \DateTimeImmutable::createFromFormat('!d.m.Y', $value);
        if (false !== $dmy && $dmy->format('d.m.Y') === $value) {
            return $dmy->format('Y-m-d');
        }

        throw new \RuntimeException(sprintf('Ozon Performance: не удалось распарсить дату "%s" (ожидается YYYY-MM-DD или DD.MM.YYYY)', $value));
    }

    private function normalizeDecimal(string $raw): string
    {
        $v = trim(str_replace(',', '.', $raw));

        return '' === $v ? '0' : $v;
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

        if (401 === $statusCode) {
            throw new OzonAuthExpiredException();
        }

        // 403 ≠ «токен истёк»: это permanent denial — нет скоупа «Продвижение»
        // или client_id заблокирован у Ozon. Ретраиться бессмысленно, падаем
        // сразу с явным сообщением (а не через общий HTTP %d).
        if (403 === $statusCode) {
            throw new \RuntimeException(sprintf('Ozon Performance: %s %s вернул 403 (недостаточно прав у client_id)', $method, $urlOrPath));
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(sprintf('Ozon Performance: %s %s вернул HTTP %d', $method, $urlOrPath, $statusCode));
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

    /**
     * Безопасный stringify поля ответа Ozon Performance API.
     *
     * Ozon может вернуть в поле error структурированное значение ({"code": "...", "details": [...]}).
     * Прямой `(string) $value` на массиве/объекте даёт литерал "Array" + PHP Warning и теряет
     * диагностическую информацию в сообщении исключения. Используем json_encode как fallback.
     */
    private function stringifyApiField(mixed $value): string
    {
        if (null === $value) {
            return '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $encoded = json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return false === $encoded ? 'non-serializable error payload' : $encoded;
    }
}
