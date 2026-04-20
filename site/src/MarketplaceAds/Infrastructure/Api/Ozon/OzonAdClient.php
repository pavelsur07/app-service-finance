<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Infrastructure\Api\Ozon;

use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAds\Entity\OzonAdPendingReport;
use App\MarketplaceAds\Enum\OzonAdPendingReportState;
use App\MarketplaceAds\Exception\OzonStatisticsQueueFullException;
use App\MarketplaceAds\Infrastructure\Api\Contract\AdPlatformClientInterface;
use App\MarketplaceAds\Repository\OzonAdPendingReportRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
 *   3) POST /api/client/statistics батчами (до 10 campaignIds) → UUID отчёта;
 *   4) GET  /api/client/statistics/{uuid} — polling до READY (макс. 120 попыток × 5с = 10 мин,
 *      с ранним exit'ом через 5 мин, если state удерживается на NOT_STARTED);
 *   5) GET  /api/client/statistics/report — скачать CSV (либо сразу из state.report.link);
 *   6) преобразовать строки CSV в формат {"rows": [{campaign_id, campaign_name, sku, spend, views, clicks}]}.
 *
 * 401 на любом запросе после получения токена → сбрасываем кэш и пробуем один раз
 * заново (актуально, если кто-то параллельно отозвал токен в ЛК Ozon). 403 трактуется
 * как permanent denial (нет скоупа «Продвижение») и ретраится не будет.
 */
class OzonAdClient implements AdPlatformClientInterface
{
    private const BASE_URL = 'https://api-performance.ozon.ru';
    private const TOKEN_PATH = '/api/client/token';
    private const CAMPAIGN_PATH = '/api/client/campaign';
    private const STATISTICS_PATH = '/api/client/statistics';
    private const STATISTICS_STATE_PATH = '/api/client/statistics/%s';
    private const STATISTICS_REPORT_PATH = '/api/client/statistics/report';

    private const REQUEST_TIMEOUT = 30;
    private const TOKEN_TTL_SAFETY_MARGIN = 300;
    // Ozon Performance API принимает до 10 campaignIds в теле /statistics
    // (ранее было 100; лимит ужесточён на стороне Ozon, подтверждено ответом
    // «Превышен лимит по количеству кампаний (максимум 10)»).
    private const STATISTICS_BATCH_SIZE = 10;
    // POLL_MAX_ATTEMPTS × POLL_INTERVAL_SECONDS = 600 сек (10 мин) общий
    // таймаут на формирование отчёта Ozon. Увеличено с 3 до 10 минут после
    // деградации Ozon Performance API: длинные IN_PROGRESS-фазы — норма для
    // больших диапазонов, и 3-минутного окна хватало только на свежие узкие
    // чанки. Ранний выход по NOT_STARTED (см. ниже) защищает от зависания
    // в очереди, так что общий таймаут можно держать большим без риска
    // блокировать слот на 10 минут зря.
    private const POLL_MAX_ATTEMPTS = 120;
    private const POLL_INTERVAL_SECONDS = 5;
    // Early-fail, если state держится NOT_STARTED дольше этого окна — значит
    // очередь Ozon Performance перегружена, и отчёт скорее всего никогда не
    // начнёт формироваться в разумное время. Отпускаем слот handler'а и
    // отдаём управление пользователю, чтобы он повторил загрузку позже.
    // Ограничено отдельной константой (а не production-по-времени-ожидания),
    // чтобы успеть отпустить слот до того, как истечёт общий 10-минутный
    // бюджет — иначе разница между «очередь» и «ошибка формирования»
    // теряется в логах и метриках.
    private const POLL_NOT_STARTED_MAX_SECONDS = 300;
    // Максимальный возраст in-flight записи (с момента requestedAt), при
    // котором resume имеет смысл. Старше этого порога отчёт считается
    // мёртвым — лучше начать новый POST /statistics, чем продолжать polling
    // UUID, который уже вышел за любой разумный SLA Ozon'а.
    private const RESUME_MAX_AGE_SECONDS = 900;
    private const CACHE_KEY_TOKEN_PREFIX = 'ozon_perf_token_';

    // Для «свежих» диапазонов (dateFrom в пределах этого окна от сегодня) отсекаем
    // явно неактивные кампании на клиенте, чтобы не спрашивать у Ozon статистику
    // по архивам. Для backfill-а более старых периодов (или длинных чанков,
    // которые начинаются до cutoff) фильтр не применяется — архивная сегодня
    // кампания могла откручиваться тогда.
    private const RECENT_DAYS_THRESHOLD = 14;
    private const ACTIVE_CAMPAIGN_STATES = [
        'CAMPAIGN_STATE_RUNNING',
        'CAMPAIGN_STATE_PLANNED',
        'CAMPAIGN_STATE_STOPPED',
    ];

    /**
     * Счётчик итераций последнего pollReport() — используется инструментированием
     * fetchAdStatisticsRange(), чтобы не ломать публичную сигнатуру pollReport
     * и не пробрасывать значения через withAuthRetry-замыкания.
     */
    private int $lastPollAttempts = 0;

    /**
     * Сырые выгрузки отчётов, собранные в последнем вызове
     * fetchAdStatisticsRange() / fetchAdStatistics(). Используются handler'ом
     * для сохранения bronze-слоя: один chunk может содержать несколько
     * физических запросов к Ozon (батчи по 10 кампаний), и каждому
     * соответствует отдельный отчёт-файл. Сбрасывается на старте каждой
     * публичной операции fetch — НЕ читать между вызовами.
     *
     * @var list<OzonReportDownload>
     */
    private array $lastChunkDownloads = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly MarketplaceFacade $marketplaceFacade,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'monolog.logger.marketplace_ads')]
        private readonly LoggerInterface $marketplaceAdsLogger,
        private readonly OzonAdPendingReportRepository $pendingReportRepo,
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
        $this->lastChunkDownloads = [];

        $credentials = $this->resolveCredentials($companyId);
        $clientId = $credentials['client_id'];
        $clientSecret = $credentials['client_secret'];

        $this->marketplaceAdsLogger->info('Ozon Performance: начало загрузки статистики', [
            'companyId' => $companyId,
            'date' => $date->format('Y-m-d'),
        ]);

        $campaigns = $this->withAuthRetry(
            $companyId,
            $clientId,
            $clientSecret,
            fn (string $token): array => $this->listSkuCampaigns($token),
        );

        $this->marketplaceAdsLogger->info('Ozon Performance: получен список SKU-кампаний', [
            'companyId' => $companyId,
            'count' => count($campaigns),
        ]);

        $campaigns = $this->filterCampaignsForDateRange($campaigns, $date);

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
                fn (string $token): string => $this->requestStatistics(
                    $token,
                    $companyId,
                    $campaignIds,
                    $date,
                    $date,
                    'NO_GROUP_BY',
                    null,
                ),
            );

            $this->marketplaceAdsLogger->info('Ozon Performance: запрошен отчёт', [
                'companyId' => $companyId,
                'reportUuid' => $uuid,
                'campaignCount' => count($campaignIds),
            ]);

            $reportLink = $this->withAuthRetry(
                $companyId,
                $clientId,
                $clientSecret,
                fn (string $token): string => $this->pollReport($token, $companyId, $uuid),
            );

            $download = $this->withAuthRetry(
                $companyId,
                $clientId,
                $clientSecret,
                fn (string $token): OzonReportDownload => $this->downloadReport($token, $reportLink, $uuid),
            );
            // Сохраняем успешный download для bronze-слоя — только ПОСЛЕ withAuthRetry,
            // чтобы 401-ретрай не зафиксировал неуспешную выгрузку.
            $this->lastChunkDownloads[] = $download;

            foreach ($this->convertCsvToRows($download->csvParts, $namesById) as $row) {
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
        ?string $jobId = null,
    ): array {
        $this->lastChunkDownloads = [];

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

            $this->marketplaceAdsLogger->info('Ozon Performance: начало загрузки статистики (range)', [
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

            $this->marketplaceAdsLogger->info('Ozon Performance: получен список SKU-кампаний (range)', [
                'companyId' => $companyId,
                'count' => count($campaigns),
            ]);

            $campaigns = $this->filterCampaignsForDateRange($campaigns, $dateFrom);
            // $campaignsCount отражает число кампаний, которые реально пойдут в
            // /statistics (после клиентского фильтра по state) — итоговый
            // summary-лог должен показывать фактическую работу, а не размер
            // списка из Ozon. Детализация «до/после» уже есть в
            // `Campaigns filtered by state`.
            $campaignsCount = count($campaigns);

            /** @var array<string, array<string, array{campaign_id: string, campaign_name: string, rows: list<array{sku: string, spend: string, views: int, clicks: int}>}>> $byDate */
            $byDate = [];

            // Resume-fetch: in-flight записи текущего job'а выбираем один раз
            // до цикла, чтобы не спамить БД N запросами на N батчей. null → для
            // legacy-вызовов без jobId (например, через fetchAdStatistics()),
            // resume-логика просто не применится — ветка matchResumableReport()
            // безопасно вернёт null.
            $inFlightReports = (null !== $jobId)
                ? $this->pendingReportRepo->findInFlightByJob($companyId, $jobId)
                : [];

            foreach (array_chunk($campaigns, self::STATISTICS_BATCH_SIZE) as $batch) {
                [$campaignIds, $namesById] = $this->splitBatch($batch);

                $resumable = $this->matchResumableReport(
                    $inFlightReports,
                    $dateFrom,
                    $dateTo,
                    $campaignIds,
                    $companyId,
                );

                if (null !== $resumable) {
                    $uuid = $resumable->getOzonUuid();
                    // requestedAt хранится с секундной точностью; для таймаута
                    // NOT_STARTED (300s) погрешность в 1 секунду незначима.
                    $pollStartedAt = (float) $resumable->getRequestedAt()->getTimestamp();

                    $this->marketplaceAdsLogger->info('Resuming existing Ozon UUID instead of creating new', [
                        'companyId' => $companyId,
                        'reportUuid' => $uuid,
                        'jobId' => $jobId,
                        'ageSeconds' => (int) round(microtime(true) - $pollStartedAt),
                        'campaignCount' => count($campaignIds),
                    ]);
                } else {
                    $uuid = $this->withAuthRetry(
                        $companyId,
                        $clientId,
                        $clientSecret,
                        fn (string $token): string => $this->requestStatistics(
                            $token,
                            $companyId,
                            $campaignIds,
                            $dateFrom,
                            $dateTo,
                            'DATE',
                            $jobId,
                        ),
                    );
                    $pollStartedAt = null;

                    $this->marketplaceAdsLogger->info('Ozon Performance: запрошен отчёт (range)', [
                        'companyId' => $companyId,
                        'reportUuid' => $uuid,
                        'campaignCount' => count($campaignIds),
                    ]);
                }

                $reportLink = $this->withAuthRetry(
                    $companyId,
                    $clientId,
                    $clientSecret,
                    fn (string $token): string => $this->pollReport($token, $companyId, $uuid, $pollStartedAt),
                );
                // pollReport не возвращает attempts, чтобы не ломать публичную
                // сигнатуру — счётчик читаем через $this->lastPollAttempts и
                // суммируем по всем батчам.
                $totalPollAttempts += $this->lastPollAttempts;

                $download = $this->withAuthRetry(
                    $companyId,
                    $clientId,
                    $clientSecret,
                    fn (string $token): OzonReportDownload => $this->downloadReport($token, $reportLink, $uuid),
                );
                // Сохраняем успешный download для bronze-слоя — только ПОСЛЕ withAuthRetry,
                // чтобы 401-ретрай не зафиксировал неуспешную выгрузку.
                $this->lastChunkDownloads[] = $download;

                foreach ($this->convertCsvToRowsByDate($download->csvParts, $namesById) as $date => $campaignsForDate) {
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

            $this->marketplaceAdsLogger->info('Ozon ad statistics fetched', [
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
            throw new OzonPermanentApiException('Ozon Performance не подключен');
        }

        $clientId = (string) ($credentials['client_id'] ?? '');
        $clientSecret = (string) ($credentials['api_key'] ?? '');
        if ('' === $clientId || '' === $clientSecret) {
            throw new OzonPermanentApiException('Ozon Performance: отсутствует client_id или client_secret');
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
     * @param list<array{id: string, title: string, state: string}> $batch
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
            $this->marketplaceAdsLogger->info('Ozon Performance: токен отклонён (401), повторяю с новым', [
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
     * Возвращает все SKU-кампании компании вместе с их state. Фильтр по state
     * на уровне query-параметра GET /api/client/campaign не передаётся —
     * Ozon API может тихо проигнорировать неподдерживаемый параметр. Отсев
     * архивных/неактивных кампаний выполняется на клиенте через
     * {@see self::filterCampaignsForDateRange()} только для «свежих» диапазонов.
     *
     * @return list<array{id: string, title: string, state: string}>
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
                'state' => (string) ($campaign['state'] ?? ''),
            ];
        }

        return $result;
    }

    /**
     * Клиентский фильтр кампаний по state для «свежих» диапазонов.
     *
     * backfillMode определяется по $dateFrom: если начало диапазона старше
     * RECENT_DAYS_THRESHOLD дней, весь чанк считаем backfill-ом и возвращаем
     * кампании без изменений — иначе при длинных чанках (до 62 дней),
     * заканчивающихся сегодня, мы бы дропнули рекламу, которая была активна
     * в начале диапазона, а сегодня уже в ARCHIVED. Для недавних диапазонов
     * (оба конца в пределах 14 дней) оставляем только ACTIVE_CAMPAIGN_STATES
     * (+ пустой state) и логируем разбивку отсечённых состояний в канал
     * marketplace_ads.
     *
     * @param list<array{id: string, title: string, state: string}> $campaigns
     *
     * @return list<array{id: string, title: string, state: string}>
     */
    private function filterCampaignsForDateRange(
        array $campaigns,
        \DateTimeImmutable $dateFrom,
    ): array {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow'));
        $cutoffDate = $today->modify('-'.self::RECENT_DAYS_THRESHOLD.' days');
        // Решаем по $dateFrom: если хотя бы один день чанка старше cutoff —
        // нельзя дропать ARCHIVED/INACTIVE, они могли откручиваться тогда.
        $backfillMode = $dateFrom < $cutoffDate;

        if ($backfillMode) {
            $this->marketplaceAdsLogger->info('Campaigns filtered by state', [
                'totalCampaigns' => count($campaigns),
                'filteredCampaigns' => count($campaigns),
                'skippedStates' => [],
                'backfillMode' => true,
            ]);

            return $campaigns;
        }

        $filtered = [];
        $skippedStates = [];
        foreach ($campaigns as $campaign) {
            $state = $campaign['state'];
            if ('' === $state || in_array($state, self::ACTIVE_CAMPAIGN_STATES, true)) {
                $filtered[] = $campaign;
                continue;
            }
            $skippedStates[$state] = ($skippedStates[$state] ?? 0) + 1;
        }

        $this->marketplaceAdsLogger->info('Campaigns filtered by state', [
            'totalCampaigns' => count($campaigns),
            'filteredCampaigns' => count($filtered),
            'skippedStates' => $skippedStates,
            'backfillMode' => false,
        ]);

        return $filtered;
    }

    /**
     * @param list<string>         $campaignIds
     * @param 'NO_GROUP_BY'|'DATE' $groupBy
     */
    private function requestStatistics(
        string $token,
        string $companyId,
        array $campaignIds,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        string $groupBy,
        ?string $jobId,
    ): string {
        // Caller передаёт DateTimeImmutable в своей TZ (в проде date.timezone=Europe/Moscow).
        // Ozon ждёт google.protobuf.Timestamp (RFC3339 в UTC), поэтому сначала фиксируем
        // границы суток в исходной TZ (00:00:00 и 23:59:59), затем конвертируем instant
        // в UTC — иначе календарный день MSK был бы помечен как UTC и сдвинул окно на 3 часа.
        $utc = new \DateTimeZone('UTC');
        $from = $dateFrom->setTime(0, 0, 0)->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
        $to = $dateTo->setTime(23, 59, 59)->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');

        $this->marketplaceAdsLogger->debug('Ozon Performance POST /statistics payload', [
            'campaign_count' => count($campaignIds),
            'from' => $from,
            'to' => $to,
            'groupBy' => $groupBy,
            'campaigns' => $campaignIds,
        ]);

        $response = $this->authorizedRequest('POST', self::STATISTICS_PATH, $token, [
            'json' => [
                'campaigns' => $campaignIds,
                'from' => $from,
                'to' => $to,
                'groupBy' => $groupBy,
            ],
        ]);

        $data = $this->decodeJson($response->getContent(false), 'statistics request');

        $uuid = $this->stringifyApiField($data['UUID'] ?? $data['uuid'] ?? null);
        if ('' === $uuid) {
            throw new \RuntimeException('Ozon Performance: ответ /statistics не содержит UUID');
        }

        // Persist UUID сразу (persist + flush внутри create()), до любого polling'а —
        // если следующий шаг упадёт exception'ом / таймаутом / рестартом воркера,
        // запись останется в БД как видимая точка диагностики и база для resume-логики.
        $this->pendingReportRepo->create(
            companyId: $companyId,
            ozonUuid: $uuid,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            campaignIds: $campaignIds,
            jobId: $jobId,
        );

        return $uuid;
    }

    /**
     * Ищет in-flight запись, пригодную для resume текущего батча: совпадают
     * dateFrom / dateTo / campaignIds (как множество). Возвращает первое
     * найденное совпадение — in-flight записи ограничены одним jobId, а
     * внутри job'а пересечения по (dateRange, campaignIds) не допускаются
     * дизайном (в цикле мы перебираем непересекающиеся батчи array_chunk).
     *
     * Stale-записи (старше RESUME_MAX_AGE_SECONDS) финализируются как
     * ABANDONED с reason='Resume threshold exceeded' и пропускаются — лучше
     * начать новый POST /statistics, чем polling'ить UUID, который Ozon
     * уже забыл. После abandon'а продолжаем сканирование: если в том же
     * job'е есть stale + fresh пара (например, после гонки Messenger-воркеров),
     * хотим финализировать stale и всё-таки подхватить fresh, а не создавать
     * третий UUID.
     *
     * @param list<OzonAdPendingReport> $inFlightReports
     * @param list<string>              $campaignIds
     */
    private function matchResumableReport(
        array $inFlightReports,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        array $campaignIds,
        string $companyId,
    ): ?OzonAdPendingReport {
        // campaignIds сравниваем как set (отсортированные list'ы), а не
        // как ordered-list: порядок campaignIds на входе в requestStatistics
        // зависит от сортировки в listSkuCampaigns и может меняться между
        // запусками, хотя логически батч тот же.
        $needle = $campaignIds;
        sort($needle);

        $now = microtime(true);
        $dateFromYmd = $dateFrom->format('Y-m-d');
        $dateToYmd = $dateTo->format('Y-m-d');

        foreach ($inFlightReports as $report) {
            if ($report->getDateFrom()->format('Y-m-d') !== $dateFromYmd) {
                continue;
            }
            if ($report->getDateTo()->format('Y-m-d') !== $dateToYmd) {
                continue;
            }

            $existing = $report->getCampaignIds();
            sort($existing);
            if ($existing !== $needle) {
                continue;
            }

            $ageSeconds = $now - (float) $report->getRequestedAt()->getTimestamp();
            if ($ageSeconds > self::RESUME_MAX_AGE_SECONDS) {
                $this->marketplaceAdsLogger->warning('Stale Ozon UUID found, abandoning before new request', [
                    'companyId' => $companyId,
                    'reportUuid' => $report->getOzonUuid(),
                    'ageSeconds' => (int) round($ageSeconds),
                    'thresholdSeconds' => self::RESUME_MAX_AGE_SECONDS,
                ]);
                $this->pendingReportRepo->markFinalized(
                    $companyId,
                    $report->getOzonUuid(),
                    OzonAdPendingReportState::ABANDONED,
                    'Resume threshold exceeded',
                );

                continue;
            }

            return $report;
        }

        return null;
    }

    /**
     * @param ?float $pollStartedAt Unix-timestamp старта polling'а для NOT_STARTED-таймаута.
     *                              null → используется microtime(true) (fresh UUID). Для resume-
     *                              ветки передаётся Unix-timestamp исходного requestedAt, чтобы
     *                              NOT_STARTED-окно не обнулялось при Messenger-retry.
     *
     * @return string ссылка на готовый отчёт (CSV)
     */
    private function pollReport(
        string $token,
        string $companyId,
        string $uuid,
        ?float $pollStartedAt = null,
    ): string {
        $startedAt = $pollStartedAt ?? microtime(true);

        for ($attempt = 1; $attempt <= self::POLL_MAX_ATTEMPTS; ++$attempt) {
            $this->lastPollAttempts = $attempt;
            $response = $this->authorizedRequest(
                'GET',
                sprintf(self::STATISTICS_STATE_PATH, rawurlencode($uuid)),
                $token,
            );
            $data = $this->decodeJson($response->getContent(false), 'statistics state');

            $state = $this->stringifyApiField($data['state'] ?? null);
            $now = new \DateTimeImmutable();
            $waitedSecondsFloat = microtime(true) - $startedAt;
            $waitedSeconds = round($waitedSecondsFloat, 1);
            $isNotStartedPhase = 'NOT_STARTED' === $state;

            $this->marketplaceAdsLogger->info('Ozon poll iteration', [
                'reportUuid' => $uuid,
                'attempt' => $attempt,
                'state' => $state,
                'waitedSeconds' => $waitedSeconds,
                'isNotStartedPhase' => $isNotStartedPhase,
            ]);

            // firstNonPendingAt фиксируется, как только отчёт сошёл с «NOT_STARTED»:
            // IN_PROGRESS, OK/READY, ERROR/CANCELLED/NOT_FOUND и любой неизвестный
            // не-пустой state — все означают, что Ozon начал работать с отчётом.
            // COALESCE в updateState() защищает от перезаписи уже установленного timestamp.
            $firstNonPendingAt = ('' !== $state && 'NOT_STARTED' !== $state) ? $now : null;
            $this->pendingReportRepo->updateState(
                $companyId,
                $uuid,
                $state,
                $now,
                $attempt,
                $firstNonPendingAt,
            );

            // Early-fail по NOT_STARTED: проверяется ПЕРЕД OK/ERROR-обработкой,
            // чтобы не пропустить переход state в терминальный после долгой
            // очереди — правая ветка важнее (если Ozon вдруг вернул OK на этой
            // же итерации, отпускать слот через exception было бы неправильно).
            // ПОСЛЕ updateState: хотим зафиксировать последний полученный state
            // в БД перед exception'ом, иначе диагностика «почему сделали
            // abandon» теряется.
            if ($isNotStartedPhase && $waitedSecondsFloat > self::POLL_NOT_STARTED_MAX_SECONDS) {
                $waitedSecondsInt = (int) round($waitedSecondsFloat);
                $this->pendingReportRepo->markFinalized(
                    $companyId,
                    $uuid,
                    OzonAdPendingReportState::ABANDONED,
                    sprintf('NOT_STARTED timeout: %d seconds', $waitedSecondsInt),
                );

                throw new OzonStatisticsQueueFullException($uuid, $waitedSecondsInt);
            }

            if ('OK' === $state || 'READY' === $state) {
                $link = $this->stringifyApiField($data['link'] ?? $data['report']['link'] ?? null);
                if ('' === $link) {
                    // Старые версии API не отдают link отдельно — отчёт скачивается
                    // по фиксированному /report?UUID=…
                    $link = self::STATISTICS_REPORT_PATH.'?UUID='.rawurlencode($uuid);
                }

                $this->pendingReportRepo->markFinalized($companyId, $uuid, OzonAdPendingReportState::OK);

                $this->marketplaceAdsLogger->info('Ozon Performance: отчёт готов', [
                    'reportUuid' => $uuid,
                    'attempts' => $attempt,
                    'waitedSeconds' => $waitedSeconds,
                ]);

                return $link;
            }

            if ('ERROR' === $state || 'CANCELLED' === $state || 'NOT_FOUND' === $state) {
                $errorMessage = sprintf(
                    'Ozon Performance: отчёт %s завершился со статусом %s: %s',
                    $uuid,
                    $state,
                    $this->stringifyApiField($data['error'] ?? null),
                );

                $this->pendingReportRepo->markFinalized(
                    $companyId,
                    $uuid,
                    OzonAdPendingReportState::ERROR,
                    $errorMessage,
                );

                throw new \RuntimeException($errorMessage);
            }

            sleep(self::POLL_INTERVAL_SECONDS);
        }

        $timeoutSeconds = self::POLL_MAX_ATTEMPTS * self::POLL_INTERVAL_SECONDS;
        $this->pendingReportRepo->markFinalized(
            $companyId,
            $uuid,
            OzonAdPendingReportState::ABANDONED,
            sprintf('Polling timeout after %d seconds', $timeoutSeconds),
        );

        throw new \RuntimeException(sprintf('Ozon Performance: отчёт %s не готов за %d секунд', $uuid, $timeoutSeconds));
    }

    /**
     * Скачивает отчёт и распаковывает ZIP-архив, если Ozon вернул сжатый ответ.
     *
     * Ozon Performance чаще возвращает ZIP для длинных диапазонов/мульти-файловых
     * отчётов. Без распаковки fgetcsv() получал бы magic-bytes «PK\x03\x04», не
     * находил заголовок CSV и тихо возвращал нулевой набор строк — root cause
     * бага «rows_count=0 при наличии рекламы».
     *
     * $rawBytes в возвращаемом OzonReportDownload — всегда оригинальный ответ
     * (ZIP или plain CSV), $csvContent — уже распакованный и конкатенированный
     * CSV. Для plain-ответа оба поля совпадают по содержимому.
     */
    private function downloadReport(string $token, string $reportLink, string $reportUuid): OzonReportDownload
    {
        // link может прийти и абсолютным (https://...), и относительным (/api/...).
        $url = str_starts_with($reportLink, 'http://') || str_starts_with($reportLink, 'https://')
            ? $reportLink
            : self::BASE_URL.$reportLink;

        $response = $this->authorizedRequest('GET', $url, $token, [], absoluteUrl: true);
        $rawBytes = $response->getContent(false);

        // Magic bytes локального ZIP-заголовка (PKZIP): 50 4B 03 04.
        $wasZip = strlen($rawBytes) >= 4 && "PK\x03\x04" === substr($rawBytes, 0, 4);

        if ($wasZip) {
            $extracted = $this->extractCsvFromZip($rawBytes, $reportUuid);
            $csvContent = $extracted['csvContent'];
            $csvParts = $extracted['csvParts'];
            $filesInZip = $extracted['filesInZip'];
        } else {
            $csvContent = $rawBytes;
            // Plain-CSV: одна «часть», идентичная rawBytes. Контракт csvParts
            // держит парсер (он всегда iterate по list), чтобы downloadReport
            // не подменял shape между ZIP и plain-ответом.
            $csvParts = [$rawBytes];
            $filesInZip = 0;
        }

        $sizeBytes = strlen($rawBytes);
        $sha256 = hash('sha256', $rawBytes);

        $this->marketplaceAdsLogger->info('Ozon report downloaded', [
            'report_uuid' => $reportUuid,
            'was_zip' => $wasZip,
            'size_bytes' => $sizeBytes,
            'csv_size_bytes' => strlen($csvContent),
            'files_in_zip' => $wasZip ? $filesInZip : null,
        ]);

        return new OzonReportDownload(
            rawBytes: $rawBytes,
            csvContent: $csvContent,
            csvParts: $csvParts,
            wasZip: $wasZip,
            sizeBytes: $sizeBytes,
            sha256: $sha256,
            reportUuid: $reportUuid,
            filesInZip: $filesInZip,
        );
    }

    /**
     * Распаковывает ZIP через временный файл: ZipArchive::open() работает только
     * с путями в ФС, стрим-чтение из памяти не поддерживается. Возвращает:
     *  - csvParts: список CSV-файлов verbatim (без модификаций). Каждая часть —
     *    отдельный CSV из ZIP, потенциально с собственным preamble'ом (новый
     *    Ozon-формат: «;Кампания по продвижению № N…»). Парсер обязан
     *    обрабатывать каждый элемент отдельно, иначе в multi-campaign ZIP'е
     *    header-строки частей 2+ попадут в data-поток, а campaign_id всех
     *    строк будет приписан единственному preamble первой части.
     *  - csvContent: legacy-concat с удалением первой строки у частей 2+.
     *    Нужен только для обратной совместимости bronze-инспекции и тестов,
     *    парсером НЕ используется.
     *  - filesInZip: общее число файлов в архиве (включая не-CSV).
     *
     * Файлы без .csv-расширения игнорируются (манифесты и т.п.) — не попадают
     * ни в csvParts, ни в csvContent.
     *
     * @return array{csvContent: string, csvParts: list<string>, filesInZip: int}
     *
     * @throws \RuntimeException если архив повреждён либо не содержит ни одного CSV
     */
    private function extractCsvFromZip(string $zipBytes, string $reportUuid): array
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'ozon-bronze-');
        if (false === $tmpPath) {
            throw new \RuntimeException('Ozon Performance: не удалось создать временный файл для распаковки ZIP');
        }

        try {
            if (false === file_put_contents($tmpPath, $zipBytes)) {
                throw new \RuntimeException('Ozon Performance: не удалось записать ZIP во временный файл');
            }

            $zip = new \ZipArchive();
            $openResult = $zip->open($tmpPath);
            if (true !== $openResult) {
                throw new \RuntimeException(sprintf(
                    'Ozon Performance: не удалось открыть ZIP-отчёт (uuid=%s, size=%d bytes, ZipArchive error code=%d)',
                    $reportUuid,
                    strlen($zipBytes),
                    is_int($openResult) ? $openResult : -1,
                ));
            }

            try {
                $filesInZip = $zip->numFiles;
                $csvParts = [];
                $mergedParts = [];
                for ($i = 0; $i < $filesInZip; ++$i) {
                    $name = $zip->getNameIndex($i);
                    if (false === $name) {
                        continue;
                    }
                    if (!str_ends_with(strtolower($name), '.csv')) {
                        continue;
                    }
                    $content = $zip->getFromIndex($i);
                    if (false === $content) {
                        continue;
                    }
                    // csvParts — verbatim: парсер convertCsvToRows* обязан сам
                    // обрабатывать preamble каждой части (в новом Ozon-формате
                    // у каждого CSV своя строка «;Кампания по продвижению № N…»
                    // с campaign_id собственной кампании). Склеивание в одну
                    // строку теряло бы per-campaign attribution.
                    $csvParts[] = $content;

                    // csvContent — legacy-concat для bronze-инспекции:
                    // у всех частей кроме первой отрезаем ПЕРВУЮ строку
                    // (preamble или header — в любом случае повторяющийся
                    // служебный текст, который в склейке не нужен).
                    if ([] !== $mergedParts) {
                        $newlinePos = strpos($content, "\n");
                        if (false !== $newlinePos) {
                            $content = substr($content, $newlinePos + 1);
                        }
                    }
                    $mergedParts[] = $content;
                }
            } finally {
                $zip->close();
            }

            if ([] === $csvParts) {
                throw new \RuntimeException(sprintf(
                    'Ozon Performance: ZIP-отчёт %s не содержит CSV-файлов (всего записей в архиве: %d)',
                    $reportUuid,
                    $filesInZip,
                ));
            }

            return [
                'csvContent' => implode("\n", $mergedParts),
                'csvParts' => $csvParts,
                'filesInZip' => $filesInZip,
            ];
        } finally {
            @unlink($tmpPath);
        }
    }

    /**
     * @return list<OzonReportDownload>
     */
    public function getLastChunkDownloads(): array
    {
        return $this->lastChunkDownloads;
    }

    /**
     * Преобразует CSV отчёта Ozon Performance (NO_GROUP_BY) в плоский список строк
     * формата {campaign_id, campaign_name, sku, spend, views, clicks}.
     *
     * Принимает list<string> — по одному CSV на файл из ZIP (или один элемент
     * для plain-CSV). Каждая часть обрабатывается независимо со своим preamble'ом:
     * в новом Ozon-формате у каждой CSV в мульти-файловом ZIP — собственная
     * строка «;Кампания по продвижению № N…», и campaign_id в data-строках
     * берётся именно из неё. Единый pass'ом по concat'у терял бы attribution.
     *
     * @param list<string>          $csvParts  CSV verbatim по каждому файлу из ZIP
     * @param array<string, string> $namesById кэш campaign_name по campaign_id
     *
     * @return list<array{campaign_id: string, campaign_name: string, sku: string, spend: float, views: int, clicks: int}>
     */
    private function convertCsvToRows(array $csvParts, array $namesById): array
    {
        $rows = [];
        $totalDataRowsSeen = 0;
        $headerSample = '';
        $firstDataSample = '';

        foreach ($csvParts as $csv) {
            $preamble = $this->stripPreamble($csv);
            $preambleCampaignId = $preamble['campaign_id'];

            foreach ($this->iterateCsvAssocRows($preamble['csv']) as $row) {
                if (0 === $totalDataRowsSeen) {
                    $headerSample = implode(';', array_keys($row));
                    $firstDataSample = implode(';', array_values($row));
                }
                ++$totalDataRowsSeen;

                $sku = (string) ($row['sku'] ?? $row['ozon_sku'] ?? '');
                // findDateField вычисляется один раз и переиспользуется в
                // isFooterOrEmptyRow (обнаружение «Всего;;…») — раньше footer-
                // проверка вызывала findDateField внутри себя, давая двойной
                // проход по row-ключам на каждую строку.
                $rawDate = $this->findDateField($row);

                if ($this->isFooterOrEmptyRow($sku, $rawDate)) {
                    continue;
                }

                // campaign_id приходит из preamble "№ X" в новом формате Ozon CSV
                // (колонки campaign_id в файле нет). Для старого/fallback-формата — из колонки.
                $campaignId = '' !== $preambleCampaignId
                    ? $preambleCampaignId
                    : (string) ($row['campaign_id'] ?? $row['id'] ?? '');

                if ('' === $campaignId || '' === $sku) {
                    continue;
                }

                $rows[] = [
                    'campaign_id' => $campaignId,
                    'campaign_name' => (string) ($row['campaign_name'] ?? $namesById[$campaignId] ?? ''),
                    'sku' => $sku,
                    'spend' => (float) str_replace(',', '.', $this->pickColumn($row, ['spend', 'cost', 'расход, ₽, с ндс', 'расход'])),
                    'views' => (int) $this->pickColumn($row, ['views', 'impressions', 'показы']),
                    'clicks' => (int) $this->pickColumn($row, ['clicks', 'клики']),
                ];
            }
        }

        $this->logEmptyCsvParseResultIfNeeded($totalDataRowsSeen, $rows, $headerSample, $firstDataSample);

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
     * Принимает list<string> — по одному CSV на файл из ZIP (или один элемент
     * для plain-CSV). Каждая часть обрабатывается независимо со своим preamble'ом,
     * см. комментарий к {@see self::convertCsvToRows()}.
     *
     * @param list<string>          $csvParts  CSV verbatim по каждому файлу из ZIP
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
    private function convertCsvToRowsByDate(array $csvParts, array $namesById): array
    {
        $result = [];
        $totalDataRowsSeen = 0;
        $headerSample = '';
        $firstDataSample = '';

        foreach ($csvParts as $csv) {
            $preamble = $this->stripPreamble($csv);
            $preambleCampaignId = $preamble['campaign_id'];

            foreach ($this->iterateCsvAssocRows($preamble['csv']) as $row) {
                if (0 === $totalDataRowsSeen) {
                    $headerSample = implode(';', array_keys($row));
                    $firstDataSample = implode(';', array_values($row));
                }
                ++$totalDataRowsSeen;

                $sku = (string) ($row['sku'] ?? $row['ozon_sku'] ?? '');
                // findDateField вычисляется один раз и переиспользуется в
                // isFooterOrEmptyRow + ниже для parseDateField (до рефакторинга
                // было 2 прохода по row-ключам на каждую строку).
                $rawDate = $this->findDateField($row);

                if ($this->isFooterOrEmptyRow($sku, $rawDate)) {
                    continue;
                }

                // campaign_id приходит из preamble "№ X" в новом формате Ozon CSV
                // (колонки campaign_id в файле нет). Для старого/fallback-формата — из колонки.
                $campaignId = '' !== $preambleCampaignId
                    ? $preambleCampaignId
                    : (string) ($row['campaign_id'] ?? $row['id'] ?? '');

                if ('' === $campaignId || '' === $sku) {
                    continue;
                }

                if ('' === $rawDate) {
                    throw new \RuntimeException(sprintf('Ozon Performance: в строке отчёта отсутствует поле даты (ожидается одна из колонок: date, day, дата, день); campaign_id=%s, sku=%s', $campaignId, $sku));
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
                    'spend' => $this->normalizeDecimal($this->pickColumn($row, ['spend', 'cost', 'расход, ₽, с ндс', 'расход'])),
                    'views' => (int) $this->pickColumn($row, ['views', 'impressions', 'показы']),
                    'clicks' => (int) $this->pickColumn($row, ['clicks', 'клики']),
                ];
            }
        }

        $this->logEmptyCsvParseResultIfNeeded($totalDataRowsSeen, $result, $headerSample, $firstDataSample);

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
        foreach (['date', 'day', 'дата', 'день'] as $key) {
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
     * Детектит и отрезает preamble-строку Ozon CSV.
     *
     * Формат Ozon (апрель 2026):
     *   ;Кампания по продвижению товаров № 14275771, период 17.04.2026-18.04.2026
     *   День;sku;...
     *
     * Preamble опознаётся по двум признакам (любой достаточен):
     *  - содержит "Кампания по продвижению" (устойчивый маркер Ozon);
     *  - начинается с разделителя (';' или ','), что означает «пустая первая
     *    ячейка + произвольный текст» — защита на случай, если Ozon поменяет
     *    вступительный текст, но оставит тот же layout «пустая первая ячейка».
     *
     * Если preamble не распознан — возвращает исходный CSV без изменений и
     * пустой campaign_id, чтобы legacy-формат (header сразу в первой строке)
     * продолжал работать.
     *
     * campaign_id извлекается regex'ом `№\s*(\d+)` — ровно то место, где Ozon
     * фиксирует идентификатор кампании в preamble-тексте.
     *
     * @return array{csv: string, campaign_id: string}
     */
    private function stripPreamble(string $csv): array
    {
        $csv = ltrim($csv, "\xEF\xBB\xBF"); // drop UTF-8 BOM
        if ('' === $csv) {
            return ['csv' => $csv, 'campaign_id' => ''];
        }

        $firstNewline = strpos($csv, "\n");
        $firstLine = false === $firstNewline ? $csv : substr($csv, 0, $firstNewline);
        $firstLineTrimmed = rtrim($firstLine, "\r");

        $isPreamble = str_contains($firstLineTrimmed, 'Кампания по продвижению')
            || str_starts_with($firstLineTrimmed, ';')
            || str_starts_with($firstLineTrimmed, ',');

        if (!$isPreamble) {
            return ['csv' => $csv, 'campaign_id' => ''];
        }

        $campaignId = '';
        if (1 === preg_match('/№\s*(\d+)/u', $firstLineTrimmed, $matches)) {
            $campaignId = $matches[1];
        }

        $this->marketplaceAdsLogger->info('Ozon CSV preamble detected', [
            'extractedCampaignId' => $campaignId,
            'preambleLine' => mb_substr($firstLineTrimmed, 0, 200, 'UTF-8'),
        ]);

        $rest = false === $firstNewline ? '' : substr($csv, $firstNewline + 1);

        return ['csv' => $rest, 'campaign_id' => $campaignId];
    }

    /**
     * Возвращает значение первой непустой колонки из $keys; '' если ни одна
     * не найдена (default — числовой 0 в callers, которые дальше приводят
     * результат к (float)/(int)).
     *
     * @param array<string, string> $row
     * @param list<string>          $keys
     */
    private function pickColumn(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && '' !== trim($row[$key])) {
                return $row[$key];
            }
        }

        return '0';
    }

    /**
     * Отсекает footer-строки Ozon CSV («Всего;;...») и полностью пустые строки.
     *
     * Footer: первая колонка (date-cell) = 'всего' и/или sku пустой. Старый
     * фильтр convertCsvToRows / convertCsvToRowsByDate отсеивал по пустому
     * campaign_id + пустому sku; в новом формате campaign_id берётся из
     * preamble и в data-строках его не бывает — без этой функции footer
     * попал бы в aggregated-результат с нулями.
     *
     * $rawDate принимаем как параметр (а не вычисляем findDateField'ом изнутри),
     * потому что caller всё равно использует его ниже (для parseDateField) —
     * лишний проход по row-ключам на каждую строку не нужен.
     */
    private function isFooterOrEmptyRow(string $sku, string $rawDate): bool
    {
        if ('' === $sku) {
            return true;
        }

        $dateCell = mb_strtolower(trim($rawDate), 'UTF-8');

        return 'всего' === $dateCell || 'total' === $dateCell;
    }

    /**
     * Warning-лог, если ни одна data-строка не прошла фильтр: без него при
     * очередном изменении формата Ozon мы бы снова смотрели на rowsCount=0
     * и гадали про header'ы — теперь сразу видно, что реально пришло в CSV.
     *
     * @param array<mixed> $parsedRows
     */
    private function logEmptyCsvParseResultIfNeeded(
        int $dataRowsSeen,
        array $parsedRows,
        string $headerSample,
        string $firstDataSample,
    ): void {
        if ($dataRowsSeen > 0 && [] === $parsedRows) {
            $this->marketplaceAdsLogger->warning('Ozon CSV: all data rows filtered out', [
                'dataRowsSeen' => $dataRowsSeen,
                'headerSample' => mb_substr($headerSample, 0, 200, 'UTF-8'),
                'firstDataSample' => mb_substr($firstDataSample, 0, 200, 'UTF-8'),
            ]);
        }
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
            throw new OzonPermanentApiException(sprintf('Ozon Performance: %s %s вернул 403 (недостаточно прав у client_id)', $method, $urlOrPath));
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            try {
                $body = $response->getContent(false);
                $bodyPreview = mb_strimwidth($body, 0, 2000, '...');
            } catch (TransportExceptionInterface) {
                // Если соединение оборвётся после получения заголовков, но до
                // дочитывания тела — всё равно отдаём наверх HTTP-код, чтобы
                // диагностический статус не был подменён TransportException'ом.
                $bodyPreview = '<body unavailable>';
            }
            throw new \RuntimeException(sprintf('Ozon Performance: %s %s вернул HTTP %d, body: %s', $method, $urlOrPath, $statusCode, $bodyPreview));
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
