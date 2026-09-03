<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Action;

use App\Ingestion\Application\Command\RecordNormalizationIssueCommand;
use App\Ingestion\Application\Command\RefreshOrderStatusesCommand;
use App\Ingestion\Application\DTO\RefreshOrderStatusesResult;
use App\Ingestion\Application\Service\OrderStatusJournal;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Application\Source\Wildberries\WbResourceType;
use App\Ingestion\Domain\Service\IngestOrderStatusMapper;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Entity\IngestOrder;
use App\Ingestion\Enum\IngestOrderScheme;
use App\Ingestion\Enum\IngestOrderStatus;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\NormalizationIssueKind;
use App\Ingestion\Exception\ConnectorAuthException;
use App\Ingestion\Exception\ConnectorRateLimitedException;
use App\Ingestion\Exception\ConnectorTransientException;
use App\Ingestion\Exception\MalformedConnectorResponseException;
use App\Ingestion\Exception\RawStorageException;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Infrastructure\Api\Ozon\OzonOrdersClientInterface;
use App\Ingestion\Infrastructure\Api\Wildberries\WbOrdersClientInterface;
use App\Ingestion\Repository\IngestOrderRepository;
use App\Ingestion\Repository\IngestRawRecordRepository;
use App\Marketplace\DTO\ActiveSellerConnectionDTO;
use App\Marketplace\Facade\MarketplaceSyncFacade;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Почасовой перепрос статусов нетерминальных заказов.
 *
 * Зачем отдельный цикл. Потоки заказов фильтруются по времени СОЗДАНИЯ: Ozon
 * отдаёт отправления за окно создания, WB marketplace — тоже. Поэтому заказ
 * попадает в них один раз, и его дальнейшие смены статуса не увидит никто.
 * Единственное исключение — поток изменений WB statistics, но он сообщает
 * только об отмене.
 *
 * Почему без SyncJob и без курсора. Здесь нет позиции, которую можно
 * продвигать: множество опрашиваемых заказов задаётся их собственным
 * состоянием, а не окном времени. Заводить ради этого задачу синхронизации
 * значило бы упереться в ActiveBackfillExistsException на каждом втором часе.
 *
 * Порядок опроса — по отметке ПОПЫТКИ (`statusRefreshAttemptedAt`): сначала
 * ни разу не спрошенные (NULL), затем по возрастанию отметки, затем по `id`.
 * Не по отметке наблюдения: попытка бывает без наблюдения (404, ответ без
 * поля статуса, отсутствие заказа в успешном ответе WB), и такие заказы
 * занимали бы начало лимита вечно.
 */
final readonly class RefreshOrderStatusesAction
{
    /**
     * Максимум номеров заказов в одном запросе статусов WB.
     *
     * Это ограничение API, независимое от лимита очереди. Сейчас
     * `findNonTerminalForRefresh()` отдаёт не больше 1000 заказов, поэтому
     * разбиение фактически даёт одну итерацию; связывать два предела одной
     * константой нельзя — они про разное, и лимит очереди может вырасти, а
     * предел запроса останется прежним.
     */
    private const WB_STATUS_CHUNK = 1000;

    /** Размер страницы реестра подключений при обходе всех компаний. */
    private const CONNECTION_PAGE_SIZE = 200;

    /** Предел колонок `raw_status` и `raw_substatus`. */
    private const STATUS_TOKEN_MAX_LENGTH = 255;

    public function __construct(
        private MarketplaceSyncFacade $marketplaceSyncFacade,
        private IngestOrderRepository $orderRepository,
        private OzonOrdersClientInterface $ozonClient,
        private WbOrdersClientInterface $wbClient,
        private IngestOrderStatusMapper $statusMapper,
        private OrderStatusJournal $statusJournal,
        private RawStorageFacade $rawStorageFacade,
        private IngestRawRecordRepository $rawRecordRepository,
        private RecordNormalizationIssueAction $recordIssueAction,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RefreshOrderStatusesCommand $command): RefreshOrderStatusesResult
    {
        $startedAt = $this->applicationTime();
        $orderedAfter = $startedAt->modify(sprintf('-%d days', $command->days));

        $result = new RefreshOrderStatusesResult();

        foreach ($this->connections($command->companyId) as $connection) {
            $source = IngestSource::tryFrom($connection->marketplace);
            if (null === $source) {
                continue;
            }

            $companyId = $connection->companyId;
            $connectionRef = $connection->connectionRef;

            $orders = $this->orderRepository->findNonTerminalForRefresh(
                $companyId,
                $source,
                $connectionRef,
                $orderedAfter,
                $command->limitPerConnection,
                // У WB спросить можно только заказ с номером marketplace-api.
                // Отсев обязан идти до лимита: иначе заказы, известные лишь из
                // потока изменений, съедали бы его целиком — у них отметка
                // попытки навсегда NULL, то есть они вечно первые.
                requireExternalOrderId: IngestSource::WILDBERRIES === $source,
            );

            if ([] === $orders) {
                continue;
            }

            $result = $this->refreshConnection($result, $source, $companyId, $connectionRef, $orders);

            // Подключение обработано и сохранено — его сущности больше не
            // нужны. Без очистки карта идентичности растёт как ОБЩЕЕ число
            // обработанных заказов, а не как лимит на подключение, и
            // межкомпанейский прогон упирается в память. Хуже того, после OOM
            // следующий прогон снова начинается с первых подключений, поэтому
            // последние голодали бы постоянно.
            $this->entityManager->clear();
        }

        // Зависшие заказы ищутся ОТДЕЛЬНО от подключений. Зависание не зависит
        // от того, живо ли ещё подключение: отключённый кабинет иначе оставлял
        // бы свои заказы вечно нетерминальными и невидимыми. Проход также
        // вынесен из цикла кабинетов — у компании с двумя подключениями он
        // заводил бы вторую проблему на тот же заказ.
        $result = $this->stopStuckOrders($result, $command->companyId, $orderedAfter, $command->limitPerConnection);

        $this->entityManager->flush();

        $this->logger->info('Order status refresh finished.', [
            'companyId' => $command->companyId,
            'days' => $command->days,
            'requested' => $result->requested,
            'observed' => $result->observed,
            'changed' => $result->changed,
            'missing' => $result->missing,
            'invalid' => $result->invalid,
            'stopped' => $result->stopped,
            'failedConnections' => $result->failedConnections,
            'authFailedConnections' => $result->authFailedConnections,
            'brokenConnections' => $result->brokenConnections,
        ]);

        // Протухший ключ и сломанный эндпоинт сами не лечатся: пока человек не
        // вмешается, подключение не получает обновлений вообще. Это инцидент,
        // а не ожидаемая помеха, поэтому ОДИН агрегированный error со
        // счётчиками — не по подключению, чтобы не устраивать веер алертов.
        // Запись по каждому подключению остаётся диагностической и идёт
        // `warning`-ом: два error об одном и том же событии — это два алерта.
        if ($result->authFailedConnections > 0 || $result->brokenConnections > 0) {
            $this->logger->error('Order status refresh could not finish connections for reasons that will not pass on their own.', [
                'authFailedConnections' => $result->authFailedConnections,
                'brokenConnections' => $result->brokenConnections,
            ]);
        }

        return $result;
    }

    /**
     * Подключения — через Facade: `Infrastructure/` чужого модуля закрыт, и
     * прямой запрос был бы нарушением границы модулей.
     *
     * Прогон по всем компаниям читает реестр страницами: он растёт вместе с
     * числом клиентов, и разовая выборка целиком нарушала бы правило
     * ограниченных списочных выборок.
     *
     * @return iterable<ActiveSellerConnectionDTO>
     */
    private function connections(?string $companyId): iterable
    {
        if (null !== $companyId) {
            yield from $this->marketplaceSyncFacade->activeSellerConnections($companyId);

            return;
        }

        $after = null;
        do {
            $page = $this->marketplaceSyncFacade->activeSellerConnectionsPage(self::CONNECTION_PAGE_SIZE, $after);
            yield from $page;

            $after = [] === $page ? null : $page[array_key_last($page)]->connectionRef;
        } while (self::CONNECTION_PAGE_SIZE === count($page));
    }

    /**
     * Момент «сейчас» в зоне приложения.
     *
     * Зона приложения — то соглашение, в котором живёт схема: колонки времени
     * без зоны, и UTC-момент вернулся бы из базы сдвинутым.
     *
     * Читается заново на каждый ответ, а не один раз на прогон: между началом
     * прогона и ответом проходят минуты, и отметка из прошлого проиграла бы
     * наблюдению, которое на самом деле старше.
     */
    private function applicationTime(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    /**
     * @param list<IngestOrder> $orders
     */
    private function refreshConnection(
        RefreshOrderStatusesResult $result,
        IngestSource $source,
        string $companyId,
        string $connectionRef,
        array $orders,
    ): RefreshOrderStatusesResult {
        $poll = IngestSource::OZON === $source
            ? $this->pollOzon($companyId, $connectionRef, $orders)
            : $this->pollWildberries(
                $companyId,
                $connectionRef,
                $orders,
                // Спрашиваем про номера ЭТОЙ страницы, а считает их запрос по
                // всему подключению: при малом лимите два заказа с одним
                // номером попали бы в разные прогоны и оба получили бы статус
                // одного и того же заказа маркетплейса.
                $this->orderRepository->findDuplicateExternalOrderIds(
                    $companyId,
                    $source,
                    $connectionRef,
                    array_values(array_filter(array_map(
                        static fn (IngestOrder $order): ?string => $order->getExternalOrderId(),
                        $orders,
                    ))),
                ),
            );

        // Сбой одного подключения не отменяет уже полученного. Ответы, успевшие
        // приехать до 429 или таймаута, применяются: иначе поздняя ошибка
        // каждый час обнуляла бы весь прогресс подключения и заказы в его
        // конце не обновлялись бы никогда.
        if (null !== $poll['failure']) {
            // Три разных исхода, а не один «сбой подключения».
            //
            // 429 и таймаут пройдут сами — это `warning`. Протухший ключ и
            // ответ, нарушающий контракт целиком, сами НЕ пройдут: через час
            // будет ровно то же самое. Считать их вместе с retryable значило
            // бы закончить прогон нулевым кодом возврата, а под `--quiet` в
            // кроне это выглядит как успешный прогон при подключении, которое
            // не обновляется вовсе.
            $failure = $poll['failure'];

            $result = match (true) {
                $failure instanceof ConnectorAuthException => $result->with(authFailedConnections: 1),
                $failure instanceof MalformedConnectorResponseException => $result->with(brokenConnections: 1),
                default => $result->with(failedConnections: 1),
            };

            // WARNING, а не ERROR: неустранимость сообщает ОДИН агрегированный
            // error после обхода. Здесь — диагностика конкретного подключения,
            // и второй error об одном и том же событии дал бы веер алертов.
            $this->logger->warning('Order status refresh could not finish a connection.', [
                'companyId' => $companyId,
                'connectionRef' => $connectionRef,
                'source' => $source->value,
                // Класс, а не сообщение: в тексте транспортных исключений
                // встречаются DSN с учётными данными.
                'exceptionClass' => $poll['failure']::class,
                'attemptedBeforeFailure' => count($poll['attempts']),
            ]);
        }

        $result = $result->with(missing: $poll['missing'], invalid: $poll['invalid']);

        if ([] === $poll['attempts']) {
            return $result;
        }

        // Время сырья — момент, когда прогон по подключению закончился. У
        // каждого наблюдения при этом СВОЯ отметка, снятая сразу после его
        // ответа: подключение — это до тысячи последовательных запросов, и
        // общая отметка приписала бы первому ответу время последнего.
        $storedAt = $this->applicationTime();

        // Сырьё, его пометка, блокировка заказов, события и отметки попыток —
        // ОДНА транзакция.
        //
        // Раздельные коммиты оставляли окно, в котором сырьё уже помечено
        // окончательно пропущенным, а наблюдений ещё нет: падение здесь
        // теряло переход навсегда, потому что переразобрать такую запись
        // некому. Сеть уже отработала, поэтому транзакция короткая.
        $changed = 0;

        /** @var list<string> $auditPaths */
        $auditPaths = [];

        try {
            $this->entityManager->wrapInTransaction(function () use (
                $source,
                $companyId,
                $connectionRef,
                $poll,
                $storedAt,
                &$changed,
                &$auditPaths,
            ): void {
                $rawRecordId = null;
                if ([] !== $poll['rows']) {
                    // Ответ маркетплейса сохраняется в raw ради аудита: без него
                    // нечем объяснить, почему статус изменился именно так.
                    // Нормализация к этой записи не применяется — маппера у
                    // ресурса нет, и запись сразу помечается пропущенной, иначе
                    // она вечно висела бы в очереди.
                    $rawRecordId = $this->storeAudit($source, $companyId, $connectionRef, $poll['rows'], $storedAt, $auditPaths);
                }

                $changed = $this->applyObservations($source, $companyId, $poll, $rawRecordId);
            });
        } catch (\Throwable $exception) {
            // Объекты аудита записаны, а есть ли их строки — НЕИЗВЕСТНО.
            //
            // Удалять их здесь нельзя: исход коммита бывает неопределённым —
            // PostgreSQL мог зафиксировать строки, а клиент потерять
            // подтверждение. Удалив объект живой записи, мы оставили бы её без
            // нагрузки и без отметки `payload_pruned_at`; чтение падало бы
            // инфраструктурной ошибкой, и retention такое не чинит. Это путь
            // к необратимой потере, тогда как сирота — лишь занятое место.
            //
            // Поэтому утечка, но ВИДИМАЯ: пути уходят в error, чтобы человек
            // мог убрать объекты, убедившись, что строк на них нет. По базе их
            // иначе не найти — retention ищет кандидатов среди строк.
            $this->reportPossiblyOrphanedAudit($auditPaths, $companyId, $connectionRef, $exception);

            throw $exception;
        }

        return $result->with(
            requested: $poll['requested'],
            observed: count($poll['observations']),
            changed: $changed,
        );
    }

    /**
     * Запись результатов опроса — короткая транзакция с перечитыванием заказов
     * под блокировкой.
     *
     * Между выборкой заказов и этим моментом прошли внешние HTTP-запросы, то
     * есть секунды или минуты. За это время нормализатор мог записать более
     * свежее наблюдение, а загруженные сущности об этом не знают: Doctrine
     * считает изменения от значений, прочитанных ДО опроса, и финальный flush
     * откатил бы статус назад. Блокировка берётся ПОСЛЕ сети и только на время
     * записи — держать её во время HTTP значило бы держать её минутами.
     *
     * Отметка ПОПЫТКИ ставится каждому заказу, до которого очередь дошла, даже
     * если наблюдения не случилось: 404 и ответ без статуса иначе не двигали бы
     * ничего, и такие заказы вечно занимали бы начало очереди.
     *
     * @param array{rows: list<array<string, mixed>>, observations: list<array{orderId: string, rawStatus: string, rawSubstatus: ?string, statusAttributes: array<string, mixed>, observedAt: \DateTimeImmutable}>, attempts: array<string, \DateTimeImmutable>, requested: int, missing: int, invalid: int, failure: \Throwable|null} $poll
     */
    private function applyObservations(
        IngestSource $source,
        string $companyId,
        array $poll,
        ?string $rawRecordId,
    ): int {
        $changed = 0;
        $occurrences = [];

        // Проблемы собираются и записываются ОДНОЙ пачкой в конце.
        //
        // Поштучный вызов внутри цикла — прямой N+1: каждая проблема брала бы
        // свою блокировку сырья и свой запрос отметок, а прогон применяет до
        // тысячи наблюдений за раз и держит при этом блокировки всей пачки.
        $issues = [];

        $locked = $this->orderRepository->findManyForUpdate($companyId, array_keys($poll['attempts']));

        foreach ($poll['attempts'] as $orderId => $attemptedAt) {
            if (isset($locked[$orderId])) {
                $locked[$orderId]->markRefreshAttempted($attemptedAt);
            }
        }

        foreach ($poll['observations'] as $observation) {
            $order = $locked[$observation['orderId']] ?? null;
            if (null === $order || null === $rawRecordId) {
                continue;
            }

            $status = $this->statusMapper->map($source, $order->getScheme(), $observation['rawStatus']);

            $outcome = $this->statusJournal->observe(
                $order,
                $observation['rawStatus'],
                $status,
                $observation['observedAt'],
                $observation['rawSubstatus'],
                $rawRecordId,
                $occurrences,
            );

            // Статусные атрибуты принадлежат статусной оси и идут только с
            // принятым наблюдением — ровно как в нормализации. Без этого
            // заказ показывал бы свежий статус рядом с устаревшими
            // supplier_status и wb_status.
            if ($outcome->accepted && [] !== $observation['statusAttributes']) {
                $order->mergeAttributes($observation['statusAttributes']);
            }

            // Незнакомый токен уходит в ту же видимую очередь, что и при
            // нормализации, но ТОЛЬКО когда наблюдение попало в журнал.
            // Часовой опрос неизменного неизвестного статуса иначе плодил бы
            // по проблеме в час — до 720 копий на заказ за окно опроса, и
            // очередь на разбор превращалась бы в шум.
            //
            // Условие именно `recorded`, а не `changed`. Наблюдение,
            // проигравшее более свежему, состояние заказа не двигает, но в
            // журнал попадает — и незнакомый токен в нём такой же настоящий.
            // Пока условием было `changed`, такой токен оставался только в
            // журнале: если победившее наблюдение сделало заказ терминальным,
            // второй попытки не будет никогда, и сломанный контракт API
            // навсегда оставался бы незамеченным.
            if ($outcome->recorded && IngestOrderStatus::UNKNOWN === $status) {
                $issues[] = new RecordNormalizationIssueCommand(
                    companyId: $companyId,
                    rawRecordId: $rawRecordId,
                    operationGroupId: null,
                    kind: NormalizationIssueKind::UNKNOWN_ORDER_STATUS,
                    details: [
                        'source' => $source->value,
                        'scheme' => $order->getScheme()->value,
                        'rawStatus' => $observation['rawStatus'],
                        'externalId' => $order->getExternalId(),
                    ],
                );
            }

            if ($outcome->changed) {
                ++$changed;
            }
        }

        $this->recordIssueAction->recordMany($issues);

        return $changed;
    }

    /**
     * @param list<IngestOrder> $orders
     *
     * @return array{rows: list<array<string, mixed>>, observations: list<array{orderId: string, rawStatus: string, rawSubstatus: ?string, statusAttributes: array<string, mixed>, observedAt: \DateTimeImmutable}>, attempts: array<string, \DateTimeImmutable>, requested: int, missing: int, invalid: int, failure: \Throwable|null}
     */
    private function pollOzon(string $companyId, string $connectionRef, array $orders): array
    {
        $rows = [];
        $observations = [];
        $attempts = [];
        $requested = 0;
        $missing = 0;
        $invalid = 0;
        $failure = null;

        foreach ($orders as $order) {
            // Схема нужна, чтобы выбрать эндпоинт. Заказ с неизвестной схемой
            // спросить нечем: отправив его в FBO «по умолчанию», мы получили
            // бы ложный 404 и молча оставили заказ без обновлений.
            if (IngestOrderScheme::FBO !== $order->getScheme() && IngestOrderScheme::FBS !== $order->getScheme()) {
                ++$invalid;
                $attempts[$order->getId()] = $this->applicationTime();
                $this->logger->warning('Ozon order has no usable scheme to poll.', [
                    'companyId' => $companyId,
                    'connectionRef' => $connectionRef,
                    'externalId' => $order->getExternalId(),
                    'scheme' => $order->getScheme()->value,
                ]);

                continue;
            }

            ++$requested;

            try {
                $posting = $this->ozonClient->fetchPosting(
                    $companyId,
                    $connectionRef,
                    $order->getScheme(),
                    $order->getExternalId(),
                );
            } catch (ConnectorAuthException|ConnectorRateLimitedException|ConnectorTransientException $exception) {
                // Дальше идти незачем — ключ, лимит и сбой шлюза относятся ко
                // всему подключению, — но уже полученное сохраняется. Попытка
                // засчитывается и этому заказу: запрос был. Без отметки
                // постоянный 5xx на самом старом заказе оставлял бы его первым
                // навсегда и блокировал очередь за лимитом.
                $attempts[$order->getId()] = $this->applicationTime();
                $failure = $exception;
                break;
            } catch (MalformedConnectorResponseException $exception) {
                ++$invalid;
                $attempts[$order->getId()] = $this->applicationTime();

                // Доказательство забирается ПЕРВЫМ, до любого выхода из цикла.
                // Иначе при неожиданном HTTP-коде тело, которое клиент
                // специально приложил, выбрасывалось бы вместе с ветвлением, и
                // именно у самого интересного ответа аудита не было бы вовсе.
                $evidence = $exception->decodedPayload();
                if (null !== $evidence) {
                    $rows[] = $evidence;
                }

                // Нарушение формы относится к ОДНОМУ отправлению: прерывать
                // из-за него цикл значило бы, что одно вечно кривое
                // отправление каждый час останавливает обработку всех
                // следующих заказов кабинета. Но неожиданный HTTP-код —
                // свойство эндпоинта, и продолжать по заказам значило бы
                // сделать сотни одинаковых запросов и завершить прогон
                // успехом при сломанном API.
                if ($exception->isEndpointWide()) {
                    $failure = $exception;
                    break;
                }

                // Нарушивший контракт ответ — как раз то, ради чего аудит и
                // нужен. В лог он не идёт: там разрешены идентификаторы и
                // статусы, но не тела ответов внешних API.
                $this->logger->warning('Ozon posting response was malformed.', [
                    'companyId' => $companyId,
                    'connectionRef' => $connectionRef,
                    'externalId' => $order->getExternalId(),
                    'exceptionClass' => $exception::class,
                    'evidenceStored' => null !== $evidence,
                ]);

                continue;
            }

            // Отметка снимается СРАЗУ после ответа, а не в конце прогона: до
            // тысячи последовательных запросов растягиваются на минуты, и
            // общая отметка приписала бы первому ответу время последнего —
            // тогда устаревшее наблюдение выигрывало бы у свежего.
            $answeredAt = $this->applicationTime();
            $attempts[$order->getId()] = $answeredAt;

            if (null === $posting) {
                ++$missing;
                // Аудит нужен и для промаха: «спросили — Ozon такого
                // отправления не знает» это утверждение о данных, и через
                // месяц его нечем будет подтвердить. Тела у 404 нет, поэтому
                // строка синтетическая и помечена как таковая.
                $rows[] = [
                    'posting_number' => $order->getExternalId(),
                    '_ingestion_outcome' => 'not_found',
                ];

                continue;
            }

            // Ответ кладётся в сырьё ДО разбора статуса: именно испорченный
            // ответ и нужен как доказательство, а выброшенный он объясняет
            // ровно ничего.
            $rows[] = $posting;

            // Строгий разбор ДО формирования наблюдения.
            //
            // Число вместо строки — не статус: приняв его, мы записали бы
            // заказу UNKNOWN и сдвинули отметку наблюдения, закрыв дорогу
            // настоящему статусу. Слишком длинное значение не влезет в колонку
            // и уронит транзакцию — то есть один кривой ответ утащил бы за
            // собой все остальные заказы кабинета, вопреки правилу
            // «испорчено одно отправление — прочие продолжаются».
            $rawStatus = self::statusToken($posting['status'] ?? null);

            if (null === $rawStatus) {
                // Нарушение контракта, а не отсутствие заказа: считается
                // отдельно от честного 404, иначе одно прячется за другим.
                ++$invalid;
                $this->logger->warning('Ozon posting has no usable status token.', [
                    'companyId' => $companyId,
                    'connectionRef' => $connectionRef,
                    'externalId' => $order->getExternalId(),
                ]);

                continue;
            }

            // `substatus` — УТОЧНЕНИЕ, а не статус, и в нормализацию не
            // попадает. Непригодное уточнение отбрасывается само по себе:
            // отклонять вместе с ним весь ответ значило бы терять настоящий
            // переход — в том числе терминальный — из-за поля, которое на
            // статус не влияет. Сырьё при этом уже сохранено выше, так что
            // доказательство остаётся, а наблюдение идёт без уточнения.
            $substatusValue = $posting['substatus'] ?? null;
            $rawSubstatus = self::statusToken($substatusValue);

            if (null !== $substatusValue && null === $rawSubstatus) {
                $this->logger->warning('Ozon posting substatus is unusable and was dropped; the status itself is applied.', [
                    'companyId' => $companyId,
                    'connectionRef' => $connectionRef,
                    'externalId' => $order->getExternalId(),
                ]);
            }

            $observations[] = [
                'orderId' => $order->getId(),
                'rawStatus' => $rawStatus,
                'rawSubstatus' => $rawSubstatus,
                'statusAttributes' => [],
                'observedAt' => $answeredAt,
            ];
        }

        return [
            'rows' => $rows,
            'observations' => $observations,
            'attempts' => $attempts,
            'requested' => $requested,
            'missing' => $missing,
            'invalid' => $invalid,
            'failure' => $failure,
        ];
    }

    /**
     * @param list<IngestOrder> $orders
     * @param list<string> $duplicateExternalOrderIds номера, встречающиеся у нескольких заказов подключения
     *
     * @return array{rows: list<array<string, mixed>>, observations: list<array{orderId: string, rawStatus: string, rawSubstatus: ?string, statusAttributes: array<string, mixed>, observedAt: \DateTimeImmutable}>, attempts: array<string, \DateTimeImmutable>, requested: int, missing: int, invalid: int, failure: \Throwable|null}
     */
    private function pollWildberries(string $companyId, string $connectionRef, array $orders, array $duplicateExternalOrderIds): array
    {
        // Список, а не map: номера здесь — десятичные строки, и в ключах
        // массива PHP превратил бы их в int, размывая тип на ровном месте.
        // Коллизии редки, а сам список почти всегда пуст, поэтому линейный
        // поиск ничего не стоит.
        $collidingWbIds = $duplicateExternalOrderIds;

        // Номер marketplace-api живёт в собственной колонке, а не в JSON:
        // по ней же идёт отсев в запросе. Заказ, известный лишь из потока
        // изменений statistics, спросить не у кого — эндпоинта «статус по
        // srid» у WB нет, и его отмену приносит сам поток изменений.
        $byWbId = [];
        $attempts = [];
        $invalid = 0;

        /** @var array<string, list<string>> $collidingExternalIds */
        $collidingExternalIds = [];

        foreach ($orders as $order) {
            $externalOrderId = $order->getExternalOrderId();

            // Колонка отсеяна в SQL как NOT NULL, но «не NULL» ещё не значит
            // «номер». Нечисловое или не влезающее в int значение спросить
            // нельзя — и отметку попытки такой заказ обязан получить всё
            // равно: без неё он вечно занимал бы начало очереди и живые
            // заказы кабинета не опрашивались бы никогда.
            //
            // Номер обязан быть КАНОНИЧЕСКИМ: без ведущих нулей и не «0».
            // Дальше строка становится ключом `int`, а поиск коллизий
            // сравнивает строки — «5» и «05» разошлись бы в этих двух местах:
            // дублями они не считаются, а ключ дают один, и один заказ молча
            // затирал бы другой. Затёртый не получил бы ни наблюдения, ни
            // отметки попытки и вечно возвращался бы в начало очереди.
            //
            // Верхняя граница проверяется ОБРАТНЫМ ПРЕОБРАЗОВАНИЕМ, а не
            // числом цифр. Ровно 18 цифр — граница выдуманная: `PHP_INT_MAX`
            // девятнадцатизначен, и настоящий номер такой длины считался бы
            // браком навсегда, доводя заказ до STUCK_ORDER. Переполнение же
            // молча даёт `PHP_INT_MAX`, и обратное преобразование — это
            // единственная точная проверка того, что число вообще влезло.
            if (null === $externalOrderId
                || 1 !== preg_match('/^[1-9]\d{0,18}$/', $externalOrderId)
                || (string) (int) $externalOrderId !== $externalOrderId
            ) {
                ++$invalid;
                $attempts[$order->getId()] = $this->applicationTime();
                $this->logger->warning('Wildberries order has no usable marketplace id.', [
                    'companyId' => $companyId,
                    'connectionRef' => $connectionRef,
                    'externalId' => $order->getExternalId(),
                ]);

                continue;
            }

            $wbOrderId = (int) $externalOrderId;

            // Два наших заказа с ОДНИМ номером маркетплейса — дефект данных:
            // у WB `id` и `rid` соответствуют один к одному. Присвоение по
            // ключу молча потеряло бы один из них, и потерянный не получил бы
            // ни наблюдения, ни отметки попытки — то есть вечно возвращался бы
            // в начало очереди. Приписать один ответ двум разным заказам тоже
            // нельзя: доказательства, что это один и тот же заказ, нет.
            // Конфликтный номер в опрос не идёт вовсе — независимо от того,
            // сколько его носителей попало в эту страницу очереди.
            if (in_array($externalOrderId, $collidingWbIds, true)) {
                ++$invalid;
                $attempts[$order->getId()] = $this->applicationTime();
                $collidingExternalIds[$externalOrderId][] = $order->getExternalId();

                continue;
            }

            $byWbId[$wbOrderId] = $order;
        }

        // Один агрегированный warning на номер, а не по записи: конфликт
        // описывается множеством заказов, а не отдельным.
        foreach ($collidingExternalIds as $externalOrderId => $externalIds) {
            $this->logger->warning('Wildberries orders share one marketplace id.', [
                'companyId' => $companyId,
                'connectionRef' => $connectionRef,
                'externalOrderId' => (string) $externalOrderId,
                'externalIds' => $externalIds,
            ]);
        }

        if ([] === $byWbId) {
            return [
                'rows' => [],
                'observations' => [],
                'attempts' => $attempts,
                'requested' => 0,
                'missing' => 0,
                'invalid' => $invalid,
                'failure' => null,
            ];
        }

        $rows = [];
        $observations = [];
        $requested = 0;
        $missing = 0;
        $failure = null;

        foreach (array_chunk(array_keys($byWbId), self::WB_STATUS_CHUNK) as $chunk) {
            $requested += count($chunk);

            try {
                $page = $this->wbClient->fetchMarketplaceStatuses($companyId, $connectionRef, $chunk);
            } catch (ConnectorAuthException|ConnectorRateLimitedException|ConnectorTransientException $exception) {
                // Ответа не было вовсе, поэтому заказы этого чанка не
                // «неизвестны маркетплейсу» — их просто не спросили. Считать их
                // missing значило бы объявить сбой сети свойством данных. Но
                // попытка была, и отметку чанк получает: иначе постоянный 5xx
                // держал бы одну и ту же пачку в начале очереди вечно.
                $failedAt = $this->applicationTime();
                foreach ($chunk as $wbOrderId) {
                    $attempts[$byWbId[$wbOrderId]->getId()] = $failedAt;
                }

                $failure = $exception;
                break;
            } catch (MalformedConnectorResponseException $exception) {
                // Ответ БЫЛ, он нарушает контракт. Это дефект интеграции, а не
                // сбой подключения: считаем чанк спрошенным и идём дальше —
                // иначе постоянно кривая пачка каждый час занимала бы начало
                // очереди и заказы за лимитом не опрашивались бы никогда.
                // Исключение — неожиданный HTTP-код: он свойство эндпоинта, и
                // следующая пачка вернёт то же самое.
                ++$invalid;

                $evidence = $exception->decodedPayload();
                if (null !== $evidence) {
                    $rows[] = $evidence;
                }

                $failedAt = $this->applicationTime();
                foreach ($chunk as $wbOrderId) {
                    $attempts[$byWbId[$wbOrderId]->getId()] = $failedAt;
                }

                $this->logger->warning('Wildberries status response was malformed.', [
                    'companyId' => $companyId,
                    'connectionRef' => $connectionRef,
                    'chunkSize' => count($chunk),
                    'exceptionClass' => $exception::class,
                    'evidenceStored' => null !== $evidence,
                    'endpointWide' => $exception->isEndpointWide(),
                ]);

                if ($exception->isEndpointWide()) {
                    $failure = $exception;
                    break;
                }

                continue;
            }

            // Отметка на чанк, а не на прогон: чанков может быть много, и
            // между первым и последним проходят минуты.
            $answeredAt = $this->applicationTime();
            $statuses = $page->statuses;

            // Отбракованные строки — дефект интеграции, а не отсутствие заказа
            // у маркетплейса. Считаются отдельно, доказательство идёт в raw, а
            // пригодные строки того же ответа применяются как обычно.
            if ($page->rejectedRows > 0) {
                $invalid += $page->rejectedRows;

                if (null !== $page->evidence) {
                    // Целый разобранный ответ: в нём уже лежат исходные
                    // строки, поэтому отдельно класть их не нужно.
                    $rows[] = $page->evidence;
                }
            } else {
                // В сырьё уходят строки ОТВЕТА, а не наш разбор.
                //
                // `statuses` для этого не годятся: оси в них нормализованы, и
                // запись, объявленная сырым ответом маркетплейса, содержала бы
                // наши значения вместо чужих. Отклонение вроде `" complete "`
                // после такой записи уже не восстановить — а разбирают потом
                // именно его.
                foreach ($page->auditRows as $auditRow) {
                    $rows[] = $auditRow;
                }
            }

            foreach ($statuses as $wbOrderId => $row) {
                $order = $byWbId[$wbOrderId] ?? null;
                if (null === $order) {
                    continue;
                }

                $supplierStatus = (string) $row['supplierStatus'];
                $wbStatus = (string) $row['wbStatus'];

                $statusAttributes = [
                    'supplier_status' => $supplierStatus,
                    'wb_status' => $wbStatus,
                ];
                if (is_bool($row['isCancellable'] ?? null)) {
                    $statusAttributes['is_cancellable'] = $row['isCancellable'];
                }

                $observations[] = [
                    'orderId' => $order->getId(),
                    'rawStatus' => IngestOrderStatusMapper::encodeWbStatus($supplierStatus, $wbStatus),
                    'rawSubstatus' => null,
                    'statusAttributes' => $statusAttributes,
                    'observedAt' => $answeredAt,
                ];
            }

            // Попытка засчитывается всему чанку, на который ответ пришёл:
            // заказ, которого в ответе не оказалось, спрашивали — и без
            // отметки он вечно занимал бы начало очереди.
            foreach ($chunk as $wbOrderId) {
                $attempts[$byWbId[$wbOrderId]->getId()] = $answeredAt;
            }

            // Отбракованная строка — НЕ отсутствие заказа: она пришла, просто
            // кривая. Считать её пропущенной значило бы посчитать один заказ
            // дважды — и как invalid, и как missing — и записать в аудит
            // ложное «маркетплейс заказ не вернул».
            $rejectedIds = array_flip($page->rejectedIds);

            // Заказ, которого не оказалось в успешном ответе, — тоже промах,
            // и он тоже документируется: иначе доказательства нет ни у одной
            // стороны.
            $notReturned = 0;
            foreach ($chunk as $wbOrderId) {
                if (isset($statuses[$wbOrderId]) || isset($rejectedIds[$wbOrderId])) {
                    continue;
                }

                ++$notReturned;
                $rows[] = ['id' => $wbOrderId, '_ingestion_outcome' => 'not_found'];
            }

            $missing += $notReturned;
        }

        return [
            'rows' => $rows,
            'observations' => $observations,
            'attempts' => $attempts,
            'requested' => $requested,
            'missing' => $missing,
            'invalid' => $invalid,
            'failure' => $failure,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $writtenPaths пути записанных объектов, заполняется по ссылке
     */
    private function storeAudit(
        IngestSource $source,
        string $companyId,
        string $connectionRef,
        array $rows,
        \DateTimeImmutable $now,
        array &$writtenPaths,
    ): string {
        $resourceType = IngestSource::OZON === $source
            ? OzonResourceType::ORDER_STATUS_REFRESH
            : WbResourceType::ORDER_STATUS_REFRESH;

        // Идентификатор прогона вместо syncJobId: задачи синхронизации здесь
        // нет, но поле обязательно, и подставлять чужой идентификатор нельзя.
        $runId = Uuid::uuid7()->toString();

        // Собственной транзакции здесь нет: вызывающий держит одну на всю
        // запись результатов опроса. store() делает свой flush, но внутри
        // открытой транзакции он не коммитит — а раздельный коммит оставлял бы
        // окно, в котором сырьё помечено пропущенным, а наблюдений ещё нет.
        $records = $this->rawStorageFacade->store(new RawBatch(
            companyId: $companyId,
            connectionRef: $connectionRef,
            shopRef: $connectionRef,
            source: $source,
            resourceType: $resourceType,
            externalId: sprintf('%s:run-%s', $resourceType, $runId),
            syncJobId: $runId,
            fetchedAt: $now,
            rows: $rows,
        ));

        // Пути собираются СРАЗУ, до любой проверки: объекты уже записаны, и
        // исключение ниже оставило бы их без строк — а компенсация умеет
        // убирать только то, о чём знает.
        foreach ($records as $record) {
            $writtenPaths[] = $record->getStoragePath();
            $record->markNormalizationSkipped();
        }

        // Хранилище возвращает список, но на одну партию всегда отдаёт ровно
        // одну запись. Полагаться на это молча нельзя: если контракт когда-то
        // изменится, наблюдения привяжутся к первой записи, и события поздних
        // строк будут указывать на payload, в котором их ответа нет. Пусть
        // такое падает громко, а не портит аудит незаметно.
        if (1 !== count($records)) {
            throw new RawStorageException(sprintf('Order status refresh audit expected a single raw record, got %d.', count($records)));
        }

        return $records[0]->getId();
    }

    /**
     * @param list<string> $storagePaths
     */
    private function reportPossiblyOrphanedAudit(array $storagePaths, string $companyId, string $connectionRef, \Throwable $exception): void
    {
        if ([] === $storagePaths) {
            return;
        }

        $this->logger->error('Audit objects may be orphaned: writing observations failed and the outcome is unknown.', [
            'companyId' => $companyId,
            'connectionRef' => $connectionRef,
            'storagePaths' => $storagePaths,
            // Класс, а не сообщение: в тексте транспортных исключений
            // встречаются DSN с учётными данными.
            'exceptionClass' => $exception::class,
        ]);
    }

    /**
     * Остановка безнадёжно зависших заказов.
     *
     * Кандидаты перечитываются под блокировкой и проверяются заново: между
     * выборкой и записью конкурентная нормализация могла довести заказ до
     * терминального статуса, и остановка вместе с проблемой создавалась бы на
     * заказ, с которым уже всё в порядке.
     */
    private function stopStuckOrders(
        RefreshOrderStatusesResult $result,
        ?string $companyId,
        \DateTimeImmutable $orderedBefore,
        int $limit,
    ): RefreshOrderStatusesResult {
        // Заказ без сырья в очередь на разбор поставить нечем — проблема
        // привязывается к сырью. Но остановить его нужно всё равно.
        //
        // Раньше он отсеивался в выборке зависших, а окно опроса его уже не
        // захватывало: заказ не обновлялся, не останавливался и никуда не
        // попадал — оставался только этот счётчик, из которого нельзя сделать
        // ничего. Состояние, помеченное плохим, обязано иметь операцию,
        // переводящую его в хорошее, иначе счётчик — вечный ложный алерт.
        //
        // Уровень ERROR, а не WARNING: заказ всегда создаётся нормализацией,
        // значит сырьё у него быть обязано. Это не ожидаемый ход событий,
        // а дефект, и разбирать его придётся по логу — очереди-то нет.
        //
        // Счётчик отвечает на вопрос «сколько их ВСЕГО», а не «сколько
        // остановлено сейчас»: он считается до лимита, и сироты могут вообще
        // не попасть в текущую страницу. Сколько остановил ЭТОТ прогон —
        // отдельная строка после транзакции: смешать эти два числа значило бы
        // сообщить оператору результат, которого не было.
        $orphans = $this->orderRepository->countStuckWithoutRawRecord($orderedBefore, $companyId);
        if ($orphans > 0) {
            $this->logger->error('Stuck orders without a raw record exist; they are stopped in batches and get no review queue entry.', [
                'orders' => $orphans,
            ]);
        }

        $candidates = null === $companyId
            ? $this->orderRepository->findStuckAcrossCompanies($orderedBefore, $limit)
            : $this->orderRepository->findStuck($companyId, $orderedBefore, $limit);

        if ([] === $candidates) {
            return $result;
        }

        $candidateIds = array_map(static fn (IngestOrder $order): string => $order->getId(), $candidates);

        // Сырьё кандидатов — ДО транзакции, чтобы внутри неё блокировки
        // брались в том же порядке, что и у нормализации: сначала сырьё, потом
        // заказы.
        //
        // Карта «заказ → его сырьё на момент отбора» нужна, чтобы потом
        // отличить ДВА разных состояния: указатель сменился конкурентом (тогда
        // нужной строки мы не держим — откладываем) и строки сырья просто нет
        // (тогда откладывать бессмысленно: она не появится, а заказ вечно
        // занимал бы начало очереди).
        $rawRecordIdByOrder = [];
        foreach ($candidates as $candidate) {
            $lastRawRecordId = $candidate->getLastRawRecordId();
            if (null !== $lastRawRecordId) {
                $rawRecordIdByOrder[$candidate->getId()] = $lastRawRecordId;
            }
        }

        $candidateRawRecordIds = array_values(array_unique($rawRecordIdByOrder));

        $stopped = 0;

        $missingEvidence = 0;

        $this->entityManager->wrapInTransaction(function () use ($candidateIds, $candidateRawRecordIds, $rawRecordIdByOrder, $orderedBefore, &$stopped, &$missingEvidence): void {
            // ПОРЯДОК БЛОКИРОВОК: сначала сырьё, потом заказы.
            //
            // Нормализация иначе не может — она начинает с сырья, — поэтому
            // порядок задаёт она, а не эта команда. Обратный порядок здесь
            // складывался с ней в цикл: крон держал заказ и ждал сырьё,
            // нормализатор держал сырьё и ждал заказ. PostgreSQL разрывает цикл,
            // убивая одну из транзакций: пачка остановки откатывалась целиком, а
            // часовой прогон падал.
            $lockedRawRecordIds = array_flip($this->rawRecordRepository->lockManyForUpdate($candidateRawRecordIds));

            // Проблемы — одной пачкой в конце, по той же причине, что и в
            // applyObservations(): поштучный вызов внутри цикла брал бы
            // блокировку и читал отметки на каждый зависший заказ.
            $issues = [];

            // Строка о взятых блокировках пишется ДО блокировки заказов: по
            // ней в логе видно, что порядок соблюдён, и по ней же видно, что
            // именно держала транзакция, если следующий шаг встал в ожидание.
            $this->logger->info('Stuck order cleanup locked evidence rows before the orders.', [
                'rawRecords' => count($lockedRawRecordIds),
                'orders' => count($candidateIds),
            ]);

            // Одна блокирующая выборка на всех кандидатов. Разбивать её по
            // компаниям значило бы выполнить запрос на компанию — прямой N+1 в
            // часовом cron-пути. Компания берётся у каждого заказа своя, там
            // где создаётся проблема.
            $orders = $this->orderRepository->findManyForUpdateAcrossCompanies($candidateIds);

            // Время снимается ПОСЛЕ блокировок, а не до них: ожидание чужой
            // транзакции может занять минуты, и отметка остановки, снятая
            // раньше, оказалась бы старше состояния, на основании которого
            // заказ и остановили.
            $now = $this->applicationTime();

            foreach ($orders as $order) {
                if (!$this->isStillStuck($order, $orderedBefore)) {
                    continue;
                }

                // Проблема привязывается к сырью, из которого заказ наблюдался
                // в последний раз: у самой остановки своего payload нет, а
                // разбирающему нужно с чего-то начать.
                //
                // Заказ без сырья останавливается ТОЖЕ, просто без записи в
                // очереди. Прежде он пропускался — и тем самым не
                // останавливался никогда: окно опроса его уже не захватывало,
                // а выборка зависших отсеивала. Заказ выпадал отовсюду. Крик
                // об этом ушёл выше одним агрегированным `error`.
                $lastRawRecordId = $order->getLastRawRecordId();
                if (null === $lastRawRecordId) {
                    ++$missingEvidence;
                    $order->stopRefreshing($now);
                    ++$stopped;

                    continue;
                }

                if (!isset($lockedRawRecordIds[$lastRawRecordId])) {
                    // Указатель СМЕНИЛСЯ между выборкой и блокировкой: нужной
                    // строки мы не держим, и создание проблемы пошло бы
                    // блокировать её уже ПОСЛЕ заказа — в обратном порядке.
                    // Откладываем: заказ никуда не денется, а цикл блокировок
                    // так не возникает.
                    if (($rawRecordIdByOrder[$order->getId()] ?? null) !== $lastRawRecordId) {
                        $this->logger->warning('Stuck order changed its raw record while it was being stopped; deferred to the next run.', [
                            'companyId' => $order->getCompanyId(),
                            'orderId' => $order->getId(),
                        ]);

                        continue;
                    }

                    // Указатель ТОТ ЖЕ, а строки сырья нет. Откладывать
                    // бессмысленно — она не появится, — и заказ вечно занимал
                    // бы начало очереди зависших, не давая дойти до остальных.
                    // Останавливаем, как и заказ вовсе без сырья, и считаем в
                    // тот же агрегированный error.
                    ++$missingEvidence;
                    $order->stopRefreshing($now);
                    ++$stopped;

                    continue;
                }

                $issues[] = new RecordNormalizationIssueCommand(
                    companyId: $order->getCompanyId(),
                    rawRecordId: $lastRawRecordId,
                    operationGroupId: null,
                    kind: NormalizationIssueKind::STUCK_ORDER,
                    details: [
                        'source' => $order->getSource()->value,
                        'externalId' => $order->getExternalId(),
                        'status' => $order->getStatus()->value,
                        'orderedAt' => $order->getOrderedAt()->format(\DATE_ATOM),
                    ],
                );

                $order->stopRefreshing($now);
                ++$stopped;
            }

            $this->recordIssueAction->recordMany($issues);
        });

        if ($missingEvidence > 0) {
            // Что сделал ИМЕННО ЭТОТ прогон: указатель пуст или ведёт в
            // никуда — привязать проблему не к чему, и заказ остановлен без
            // записи в очереди. Число здесь фактическое, в отличие от общего
            // счётчика выше, который считается до лимита.
            $this->logger->error('Stuck orders were stopped without a review queue entry: their raw record is missing.', [
                'orders' => $missingEvidence,
            ]);
        }

        return $result->with(stopped: $stopped);
    }

    /**
     * Условие зависания перепроверяется по тем же признакам, что и в выборке:
     * одно доменное понятие — одно определение.
     */
    private function isStillStuck(IngestOrder $order, \DateTimeImmutable $orderedBefore): bool
    {
        return null === $order->getRefreshStoppedAt()
            && !$order->getStatus()->isTerminal()
            && $order->getOrderedAt() < $orderedBefore;
    }

    /**
     * Токен статуса: строго строка, непустая и влезающая в колонку.
     *
     * Числа сюда не годятся. `"status": 123` — нарушение контракта, а не
     * статус: приняв его, мы записали бы заказу UNKNOWN и сдвинули отметку
     * наблюдения, закрыв дорогу настоящему статусу. Длина ограничена размером
     * колонки: значение длиннее уронило бы транзакцию на записи и утащило за
     * собой все остальные заказы подключения.
     */
    private static function statusToken(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $token = trim($value);

        if ('' === $token || mb_strlen($token) > self::STATUS_TOKEN_MAX_LENGTH) {
            return null;
        }

        return $token;
    }
}
