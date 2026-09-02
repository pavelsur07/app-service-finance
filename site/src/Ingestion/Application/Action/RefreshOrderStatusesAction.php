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

    public function __construct(
        private MarketplaceSyncFacade $marketplaceSyncFacade,
        private IngestOrderRepository $orderRepository,
        private OzonOrdersClientInterface $ozonClient,
        private WbOrdersClientInterface $wbClient,
        private IngestOrderStatusMapper $statusMapper,
        private OrderStatusJournal $statusJournal,
        private RawStorageFacade $rawStorageFacade,
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
        ]);

        // Протухший ключ сам не лечится: пока человек его не заменит,
        // подключение не получает обновлений вообще. Это инцидент, а не
        // ожидаемая помеха, поэтому один агрегированный error со счётчиком —
        // не по подключению, чтобы не устраивать веер алертов.
        if ($result->authFailedConnections > 0) {
            $this->logger->error('Order status refresh could not authenticate against marketplaces.', [
                'authFailedConnections' => $result->authFailedConnections,
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
            : $this->pollWildberries($companyId, $connectionRef, $orders);

        // Сбой одного подключения не отменяет уже полученного. Ответы, успевшие
        // приехать до 429 или таймаута, применяются: иначе поздняя ошибка
        // каждый час обнуляла бы весь прогресс подключения и заказы в его
        // конце не обновлялись бы никогда.
        if (null !== $poll['failure']) {
            $result = $poll['failure'] instanceof ConnectorAuthException
                ? $result->with(authFailedConnections: 1)
                : $result->with(failedConnections: 1);

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

        $rawRecordId = null;
        if ([] !== $poll['rows']) {
            // Ответ маркетплейса сохраняется в raw ради аудита: без него нечем
            // объяснить, почему статус изменился именно так. Нормализация к
            // этой записи не применяется — маппера у ресурса нет, и запись
            // сразу помечается пропущенной, иначе она вечно висела бы в
            // очереди.
            $rawRecordId = $this->storeAudit($source, $companyId, $connectionRef, $poll['rows'], $storedAt);
        }

        return $result->with(
            requested: $poll['requested'],
            observed: count($poll['observations']),
            changed: $this->applyObservations($source, $companyId, $poll, $rawRecordId),
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

        $this->entityManager->wrapInTransaction(function () use (
            $source,
            $companyId,
            $poll,
            $rawRecordId,
            &$changed,
            &$occurrences,
        ): void {
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
                // нормализации, но ТОЛЬКО когда наблюдение действительно
                // изменило состояние. Часовой опрос неизменного неизвестного
                // статуса иначе плодил бы по проблеме в час — до 720 копий на
                // заказ за окно опроса, и очередь на разбор превращалась бы в
                // шум, в котором настоящие проблемы не найти.
                if ($outcome->changed && IngestOrderStatus::UNKNOWN === $status) {
                    ($this->recordIssueAction)(new RecordNormalizationIssueCommand(
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
                    ));
                }

                if ($outcome->changed) {
                    ++$changed;
                }
            }
        });

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
                $evidence = $exception->decodedPayload();
                if (null !== $evidence) {
                    $rows[] = $evidence;
                }

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

            $rawStatus = $this->stringOrNull($posting['status'] ?? null);
            if (null === $rawStatus) {
                // Нарушение контракта, а не отсутствие заказа: считается
                // отдельно от честного 404, иначе одно прячется за другим.
                ++$invalid;
                $this->logger->warning('Ozon posting has no status field.', [
                    'companyId' => $companyId,
                    'connectionRef' => $connectionRef,
                    'externalId' => $order->getExternalId(),
                ]);

                continue;
            }

            $observations[] = [
                'orderId' => $order->getId(),
                'rawStatus' => $rawStatus,
                'rawSubstatus' => $this->stringOrNull($posting['substatus'] ?? null),
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
     *
     * @return array{rows: list<array<string, mixed>>, observations: list<array{orderId: string, rawStatus: string, rawSubstatus: ?string, statusAttributes: array<string, mixed>, observedAt: \DateTimeImmutable}>, attempts: array<string, \DateTimeImmutable>, requested: int, missing: int, invalid: int, failure: \Throwable|null}
     */
    private function pollWildberries(string $companyId, string $connectionRef, array $orders): array
    {
        // Номер marketplace-api живёт в собственной колонке, а не в JSON:
        // по ней же идёт отсев в запросе. Заказ, известный лишь из потока
        // изменений statistics, спросить не у кого — эндпоинта «статус по
        // srid» у WB нет, и его отмену приносит сам поток изменений.
        $byWbId = [];
        $attempts = [];
        $invalid = 0;

        foreach ($orders as $order) {
            $externalOrderId = $order->getExternalOrderId();

            // Колонка отсеяна в SQL как NOT NULL, но «не NULL» ещё не значит
            // «номер». Нечисловое или не влезающее в int значение спросить
            // нельзя — и отметку попытки такой заказ обязан получить всё
            // равно: без неё он вечно занимал бы начало очереди и живые
            // заказы кабинета не опрашивались бы никогда.
            if (null === $externalOrderId || 1 !== preg_match('/^\d{1,18}$/', $externalOrderId)) {
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
            if (isset($byWbId[$wbOrderId])) {
                $collision = $byWbId[$wbOrderId];
                unset($byWbId[$wbOrderId]);

                foreach ([$collision, $order] as $conflicting) {
                    ++$invalid;
                    $attempts[$conflicting->getId()] = $this->applicationTime();
                }

                $this->logger->warning('Wildberries orders share one marketplace id.', [
                    'companyId' => $companyId,
                    'connectionRef' => $connectionRef,
                    'externalOrderId' => $externalOrderId,
                    'externalIds' => [$collision->getExternalId(), $order->getExternalId()],
                ]);

                continue;
            }

            $byWbId[$wbOrderId] = $order;
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
                $statuses = $this->wbClient->fetchMarketplaceStatuses($companyId, $connectionRef, $chunk);
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
            $answered = 0;

            foreach ($statuses as $wbOrderId => $row) {
                $order = $byWbId[$wbOrderId] ?? null;
                if (null === $order) {
                    continue;
                }

                ++$answered;
                $rows[] = $row;

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

            // Заказ, которого не оказалось в успешном ответе, — тоже промах,
            // и он тоже документируется: иначе доказательства нет ни у одной
            // стороны.
            foreach ($chunk as $wbOrderId) {
                if (!isset($statuses[$wbOrderId])) {
                    $rows[] = ['id' => $wbOrderId, '_ingestion_outcome' => 'not_found'];
                }
            }

            $missing += count($chunk) - $answered;
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
     */
    private function storeAudit(
        IngestSource $source,
        string $companyId,
        string $connectionRef,
        array $rows,
        \DateTimeImmutable $now,
    ): string {
        $resourceType = IngestSource::OZON === $source
            ? OzonResourceType::ORDER_STATUS_REFRESH
            : WbResourceType::ORDER_STATUS_REFRESH;

        // Идентификатор прогона вместо syncJobId: задачи синхронизации здесь
        // нет, но поле обязательно, и подставлять чужой идентификатор нельзя.
        $runId = Uuid::uuid7()->toString();

        // Вставка и пометка — одной транзакцией. store() делает собственный
        // flush, и падение между ним и пометкой оставило бы запись в статусе
        // PENDING: ресурс, для которого маппера не существует, вечно ждал бы
        // нормализации.
        $records = [];

        $this->entityManager->wrapInTransaction(function () use ($companyId, $connectionRef, $source, $resourceType, $runId, $rows, $now, &$records): void {
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

            foreach ($records as $record) {
                $record->markNormalizationSkipped();
            }
        });

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
        // Заказ без сырья остановить нечем — проблема привязывается к сырью, —
        // но и молчать о нём нельзя: он выпал бы и из опроса, и из очереди на
        // разбор. Один агрегированный warning со счётчиком, а не по записи.
        $orphans = $this->orderRepository->countStuckWithoutRawRecord($orderedBefore, $companyId);
        if ($orphans > 0) {
            $this->logger->warning('Stuck orders without a raw record cannot be queued for review.', [
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

        $stopped = 0;

        $this->entityManager->wrapInTransaction(function () use ($candidateIds, $orderedBefore, &$stopped): void {
            $now = $this->applicationTime();

            // Одна блокирующая выборка на всех кандидатов. Разбивать её по
            // компаниям значило бы выполнить запрос на компанию — прямой N+1 в
            // часовом cron-пути. Компания берётся у каждого заказа своя, там
            // где создаётся проблема.
            foreach ($this->orderRepository->findManyForUpdateAcrossCompanies($candidateIds) as $order) {
                if (!$this->isStillStuck($order, $orderedBefore)) {
                    continue;
                }

                // Проблема привязывается к сырью, из которого заказ наблюдался
                // в последний раз: у самой остановки своего payload нет, а
                // разбирающему нужно с чего-то начать. Заказ без сырья не
                // существует — он всегда создаётся нормализацией. Но если
                // сырьё почему-то потерялось, заказ НЕ останавливается: тихая
                // остановка без видимой очереди хуже, чем заказ, который
                // продолжают опрашивать.
                // Заказ без сырья до этой выборки не доходит: он отсеян в
                // запросе, иначе вечно занимал бы её начало. Проверка остаётся
                // как утверждение о невозможном.
                $lastRawRecordId = $order->getLastRawRecordId();
                if (null === $lastRawRecordId) {
                    continue;
                }

                ($this->recordIssueAction)(new RecordNormalizationIssueCommand(
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
                ));

                $order->stopRefreshing($now);
                ++$stopped;
            }
        });

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

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $string = trim((string) $value);

        return '' === $string ? null : $string;
    }
}
