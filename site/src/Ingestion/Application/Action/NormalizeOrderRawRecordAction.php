<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Action;

use App\Ingestion\Application\Command\NormalizeRawRecordCommand;
use App\Ingestion\Application\Command\RecordNormalizationIssueCommand;
use App\Ingestion\Application\DTO\ListingResolution;
use App\Ingestion\Application\DTO\MappedOrder;
use App\Ingestion\Application\Service\ListingResolverRegistry;
use App\Ingestion\Application\Service\OrderStatusJournal;
use App\Ingestion\Domain\Service\IngestOrderStatusMapper;
use App\Ingestion\Domain\Service\OrderMapperRegistry;
use App\Ingestion\Entity\IngestOrder;
use App\Ingestion\Entity\IngestOrderItem;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestOrderScheme;
use App\Ingestion\Enum\IngestOrderStatus;
use App\Ingestion\Enum\NormalizationIssueKind;
use App\Ingestion\Enum\RawNormalizationStatus;
use App\Ingestion\Exception\RawRecordNotFoundException;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Repository\IngestOrderItemRepository;
use App\Ingestion\Repository\IngestOrderRepository;
use App\Ingestion\Repository\IngestOrderStatusEventRepository;
use App\Ingestion\Repository\IngestRawRecordRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Нормализация сырья заказов в IngestOrder / IngestOrderItem и журнал статусов.
 *
 * Отдельное действие от финансового {@see NormalizeRawRecordAction}, а не ветка
 * внутри него: то заточено под MappedTransaction и UpsertFinancialTransaction,
 * у него уже 13 зависимостей, и рост горячего финансового пути ради заказов не
 * оправдан. Маршрутизация — в NormalizeRawRecordHandler по наличию маппера в
 * OrderMapperRegistry.
 */
final readonly class NormalizeOrderRawRecordAction
{
    public function __construct(
        private IngestRawRecordRepository $rawRecordRepository,
        private RawStorageFacade $rawStorageFacade,
        private OrderMapperRegistry $orderMapperRegistry,
        private IngestOrderStatusMapper $statusMapper,
        private ListingResolverRegistry $listingResolverRegistry,
        private IngestOrderRepository $orderRepository,
        private IngestOrderItemRepository $itemRepository,
        private OrderStatusJournal $statusJournal,
        private IngestOrderStatusEventRepository $statusEventRepository,
        private RecordNormalizationIssueAction $recordNormalizationIssueAction,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(NormalizeRawRecordCommand $command): void
    {
        $rawRecord = $this->rawRecordRepository->findByIdAndCompany($command->rawRecordId, $command->companyId);
        if (null === $rawRecord) {
            throw new RawRecordNotFoundException('Raw record not found for requested company.');
        }

        $rows = iterator_to_array($this->rawStorageFacade->read($rawRecord->getId(), $command->companyId), false);
        $mapper = $this->orderMapperRegistry->get($rawRecord->getSource(), $rawRecord->getResourceType());
        $batch = $mapper->map($rawRecord, $rows);
        $orders = $batch->orders;

        // Момент наблюдения — когда сырьё было СКАЧАНО, а не когда мы его
        // разбираем. Иначе повторная нормализация старого сырья задним числом
        // обогнала бы свежий статус и сломала монотонность.
        //
        // Зона приводится к зоне приложения ПЕРЕД сравнением и записью.
        //
        // Колонки времени в схеме — без зоны, а Doctrine пишет отметку в её
        // собственной зоне и читает обратно в зоне PHP. Проверено:
        // `2026-09-01T10:00:00+00:00` уходит в базу как `10:00:00`, а
        // возвращается как `10:00:00+03:00` — сдвиг на 10800 секунд.
        // Коннекторы берут время у ClockInterface, то есть в UTC, а остальное
        // приложение живёт в своей зоне; сравнение сохранённой отметки со
        // свежей давало бы разницу в три часа, и устаревшее наблюдение
        // проходило бы как новое.
        $observedAt = $this->applicationTime($rawRecord->getFetchedAt());

        // Заказы поднимаются ОДНИМ запросом, а не по одному на строку.
        //
        // Коннектор отдаёт до 20 000 строк за pull, и запрос на заказ означал
        // бы десятки тысяч обращений на один raw batch. Плюс промежуточный
        // flush() на каждый новый заказ создавал частичное состояние: заказ
        // сохранён, а его начальное событие и позиции — ещё нет. Падение
        // между ними оставляло заказ с тем же статусом, и повтор уже не
        // восстановил бы пропущенное событие. Теперь запись целиком —
        // заказ, событие, позиции и markNormalizationDone — уходит одним
        // flush в конце.
        // Выборка, применение и flush идут ОДНОЙ транзакцией с блокировкой на
        // запись.
        //
        // Путей записи статуса два — нормализация и почасовой перепрос, — и
        // блокировка только на стороне перепроса ничего не давала бы: writer,
        // прочитавший строку раньше, всё равно перезаписал бы её отложенным
        // UPDATE, а блокировка лишь задержала бы его. Между выборкой и flush
        // здесь идёт только локальная работа: сырьё уже прочитано из хранилища
        // и разобрано выше, сетевых вызовов внутри блокировки нет.
        $this->entityManager->wrapInTransaction(function () use (
            $command,
            $rawRecord,
            $batch,
            $orders,
            $observedAt,
        ): void {
            // Сырьё блокируется ПЕРВЫМ и перечитывается: две параллельные
            // доставки одного сообщения иначе обе увидели бы статус не DONE,
            // и вторая пошла бы разбирать как первый раз — дописав ложный
            // обратный переход. Порядок блокировок один для всех
            // нормализаторов: сначала запись сырья, потом заказы.
            $this->entityManager->lock($rawRecord, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($rawRecord);

            // Повтор определяется по самому сырью, а не по флагу команды: сюда
            // приводит и осознанный forceReplay, и повторная доставка
            // сообщения. Незавершённый первый разбор (запись не DONE) повтором
            // не считается — иначе события, не успевшие записаться, потерялись
            // бы навсегда.
            $isReplay = RawNormalizationStatus::DONE === $rawRecord->getNormalizationStatus();

            $known = $this->orderRepository->findManyByExternalIdsIndexed(
                $command->companyId,
                $rawRecord->getSource(),
                $rawRecord->getConnectionRef(),
                array_values(array_unique(array_map(
                    static fn (MappedOrder $order): string => $order->externalId,
                    $orders,
                ))),
                forUpdate: true,
            );

            // Номера уже записанных наблюдений — одним запросом на весь batch.
            // Счётчик ведётся локально и растёт сразу при создании события:
            // Doctrine-запрос не видит непрофлашенные сущности, поэтому
            // несколько наблюдений одного заказа внутри батча иначе получили бы
            // один номер и уронили финальный flush на уникальном индексе.
            $occurrences = $this->statusEventRepository->lastOccurrencesForRawRecord(
                $command->companyId,
                $rawRecord->getId(),
                array_values(array_map(
                    static fn (IngestOrder $order): string => $order->getId(),
                    $known,
                )),
            );

            // Две карты, а не одна.
            //
            // knownItems — пул сущностей для переиспользования, он не сжимается.
            // currentItems — набор ПОСЛЕДНЕГО снимка каждого заказа. Удаление
            // применяется один раз после всего батча: если удалять внутри цикла,
            // а тот же заказ встретится в батче ещё раз со снимком {A,B} после
            // {A}, Doctrine выполнит INSERT раньше DELETE и уникальный индекс
            // уронит общий flush.
            $currentItems = [];
            $knownItems = $this->itemRepository->findByOrdersIndexedByLineKey(
                $command->companyId,
                array_values(array_map(
                    static fn (IngestOrder $order): string => $order->getId(),
                    $known,
                )),
            );

            // Резолв листингов — ОДИН вызов на всё сырьё, а не на каждый заказ.
            //
            // Пакетная загрузка заказов и позиций убрала N+1 на них, но резолвер
            // оставался внутри цикла: страница коннектора несёт до 20 000 заказов,
            // и это были бы 20 000 обращений. Ключ — «индекс заказа:индекс
            // позиции», resolveMany() сохраняет ключи.
            $sourceDataRows = [];
            foreach ($orders as $orderIndex => $mappedOrder) {
                foreach ($mappedOrder->items as $itemIndex => $mappedItem) {
                    $sourceDataRows[$orderIndex.':'.$itemIndex] = $mappedItem->sourceData;
                }
            }

            $resolutions = [] === $sourceDataRows
                ? []
                : $this->listingResolverRegistry->resolveMany(
                    $rawRecord->getSource(),
                    $command->companyId,
                    $sourceDataRows,
                );

            foreach ($orders as $orderIndex => $mappedOrder) {
                // Один и тот же externalId может встретиться в батче дважды:
                // второй раз он обязан попасть в уже созданную запись, а не
                // создать вторую и упереться в уникальный индекс.
                $known[$mappedOrder->externalId] = $this->applyOrder(
                    $rawRecord,
                    $mappedOrder,
                    $observedAt,
                    $known,
                    $knownItems,
                    $currentItems,
                    $occurrences,
                    $resolutions,
                    $orderIndex,
                    $isReplay,
                );
            }

            // Строка, которую маппер не смог разобрать, обязана стать видимой
            // очередью. Курсор после нормализации уже уехал вперёд, поэтому
            // молчаливый пропуск — постоянная потеря, ничем не отличимая от
            // «заказов в окне не было».
            // Повтор проблем не заводит: они, как и события журнала, —
            // наблюдения, случившиеся один раз. Иначе повторная доставка
            // сообщения или forceReplay размножали бы одинаковые элементы
            // видимой очереди, у которой уникального ключа нет.
            foreach ($isReplay ? [] : $batch->skipped as $skipped) {
                ($this->recordNormalizationIssueAction)(new RecordNormalizationIssueCommand(
                    companyId: $command->companyId,
                    rawRecordId: $rawRecord->getId(),
                    operationGroupId: null,
                    kind: NormalizationIssueKind::MAPPER_FAILURE,
                    details: [
                        'source' => $rawRecord->getSource()->value,
                        'resourceType' => $rawRecord->getResourceType(),
                        'reason' => $skipped['reason'],
                        'externalId' => $skipped['hint'],
                    ],
                ));
            }

            // Позиции, исчезнувшие из последнего снимка заказа, удаляются: строка,
            // которой в заказе уже нет, завышала бы и сумму, и агрегаты по выкупу.
            foreach ($currentItems as $orderId => $applied) {
                foreach ($knownItems[$orderId] ?? [] as $lineKey => $item) {
                    if (!isset($applied[$lineKey])) {
                        $this->itemRepository->remove($item);
                    }
                }
            }

            $rawRecord->markNormalizationDone();
            $this->entityManager->flush();
        });

        $this->logger->info('Ingestion order raw record normalized.', [
            'companyId' => $command->companyId,
            'rawRecordId' => $rawRecord->getId(),
            'resourceType' => $rawRecord->getResourceType(),
            'orders' => count($orders),
            'skippedRows' => count($batch->skipped),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function initialAttributes(MappedOrder $mapped): ?array
    {
        $attributes = $mapped->statusObserved
            ? array_merge($mapped->attributes, $mapped->statusAttributes)
            : $mapped->attributes;

        return [] === $attributes ? null : $attributes;
    }

    /**
     * Момент в зоне приложения — то соглашение, в котором живёт схема.
     */
    private function applicationTime(\DateTimeImmutable $instant): \DateTimeImmutable
    {
        return $instant->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    /**
     * @param array<string, IngestOrder> $known externalId => заказ
     * @param array<string, array<string, IngestOrderItem>> $knownItems пул сущностей для
     *                                                                  переиспользования, не сжимается
     * @param array<string, array<string, IngestOrderItem>> $currentItems набор последнего снимка заказа
     * @param array<string, int> $occurrences последний номер наблюдения на заказ,
     *                                        изменяется по ссылке
     * @param array<array-key, ListingResolution|null> $resolutions «индекс заказа:индекс позиции»
     * @param bool $isReplay сырьё уже разбирали: наблюдений больше нет, есть пересчёт
     */
    private function applyOrder(
        IngestRawRecord $rawRecord,
        MappedOrder $mapped,
        \DateTimeImmutable $observedAt,
        array $known,
        array &$knownItems,
        array &$currentItems,
        array &$occurrences,
        array $resolutions,
        int $orderIndex,
        bool $isReplay,
    ): IngestOrder {
        $companyId = $rawRecord->getCompanyId();
        $status = $this->statusMapper->map($rawRecord->getSource(), $mapped->scheme, $mapped->rawStatus);

        if (IngestOrderStatus::UNKNOWN === $status && !$isReplay) {
            // Видимая очередь на разбор вместо тихой потери: заказ сохраняется,
            // но незнакомый токен становится заметен в существующем UI issues.
            ($this->recordNormalizationIssueAction)(new RecordNormalizationIssueCommand(
                companyId: $companyId,
                rawRecordId: $rawRecord->getId(),
                operationGroupId: null,
                kind: NormalizationIssueKind::UNKNOWN_ORDER_STATUS,
                details: [
                    'source' => $rawRecord->getSource()->value,
                    'scheme' => $mapped->scheme->value,
                    'rawStatus' => $mapped->rawStatus,
                    'externalId' => $mapped->externalId,
                ],
            ));
        }

        $order = $known[$mapped->externalId] ?? null;

        if (null === $order) {
            $order = new IngestOrder(
                companyId: $companyId,
                connectionRef: $rawRecord->getConnectionRef(),
                shopRef: $rawRecord->getShopRef(),
                source: $rawRecord->getSource(),
                scheme: $mapped->scheme,
                externalId: $mapped->externalId,
                // Дата заказа тоже приводится к зоне приложения: парсер
                // отдаёт UTC, а колонка без зоны вернула бы тот же
                // циферблат в зоне PHP, сдвинув абсолютный момент.
                orderedAt: $this->applicationTime($mapped->orderedAt),
                rawStatus: $mapped->rawStatus,
                status: $status,
                // Отметка ставится, только если статус реально наблюдался:
                // иначе первое настоящее наблюдение, окажись оно старше по
                // времени скачивания, было бы отвергнуто как устаревшее.
                statusObservedAt: $mapped->statusObserved ? $observedAt : null,
                externalOrderId: $mapped->externalOrderId,
                rawSubstatus: $mapped->rawSubstatus,
                lastRawRecordId: $rawRecord->getId(),
                // Статусные атрибуты — только если статус реально наблюдался.
                //
                // Иначе строка statistics с `isCancel = false` записала бы
                // `is_cancel = false` при непринятом статусе, и пришедшее
                // следом первое настоящее наблюдение об отмене дало бы
                // противоречие: status = CANCELLED рядом с is_cancel = false.
                attributes: $this->initialAttributes($mapped),
            );
            // Отметка своей оси ставится и при создании — иначе следующее,
            // более старое наблюдение того же вида прошло бы как первое.
            if ($mapped->itemsAuthoritative) {
                $order->acceptSnapshot($observedAt);
            } else {
                $order->acceptPartialObservation($observedAt);
            }

            $this->orderRepository->save($order);

            // Событие журнала — факт НАБЛЮДЕНИЯ статуса. Без наблюдения его
            // нет: выдуманная строка «статус UNKNOWN» ничего не фиксирует.
            //
            // Повтор событий не создаёт и здесь. Заказ может появиться на
            // повторе — например, его удалили, или маппер научился разбирать
            // строку, которую раньше пропускал, — но наблюдение произошло один
            // раз, тогда же оно и попало в журнал. Записать открывающее
            // событие сейчас значило бы датировать наблюдение моментом
            // повтора и получить журнал, в котором есть начало и нет ни одного
            // из последующих переходов: те идут через reapply() и событий не
            // пишут.
            if ($mapped->statusObserved && !$isReplay) {
                $this->statusJournal->recordOpening($order, $mapped->rawStatus, $status, $observedAt, $rawRecord->getId(), $occurrences);
            }
            $applied = $this->applyItems($order, $mapped, [], $resolutions, $orderIndex);
            $knownItems[$order->getId()] = $applied;
            if ($mapped->itemsAuthoritative) {
                $currentItems[$order->getId()] = $applied;
            }

            return $order;
        }

        // Свежесть СТАТУСА и свежесть СНИМКА — разные вещи.
        //
        // observeStatus() возвращает false для устаревшего наблюдения и статус
        // назад не двигает: старое сырьё не должно откатывать состояние.
        // Но привязывать к той же отметке состав и цену нельзя: потоки
        // приходят вперемешку, и частичное наблюдение, скачанное позже, а
        // разобранное раньше, навсегда закрыло бы дорогу авторитетному снимку
        // — заказ остался бы без номера, валюты и с ценой другой семантики.
        //
        // Поэтому у снимка своя отметка. Событие журнала пишется при первом
        // разборе: оно фиксирует факт наблюдения, а не текущее состояние.
        // Наблюдение без статуса статуса не двигает и события не пишет:
        // отсутствие статуса — не статус.
        //
        // Повтор уже разобранного сырья наблюдением не является и идёт через
        // reapply(): иначе он вёл бы себя по-разному в зависимости от момента
        // повтора — см. комментарий в OrderStatusJournal.
        $statusAccepted = $mapped->statusObserved
            && ($isReplay
                ? $this->statusJournal->reapply(
                    $order,
                    $mapped->rawStatus,
                    $status,
                    $observedAt,
                    $mapped->rawSubstatus,
                    $rawRecord->getId(),
                )
                : $this->statusJournal->observe(
                    $order,
                    $mapped->rawStatus,
                    $status,
                    $observedAt,
                    $mapped->rawSubstatus,
                    $rawRecord->getId(),
                    $occurrences,
                ))->accepted;

        // Полный снимок применяется, если он новее ПОСЛЕДНЕГО полного снимка.
        // Частичное наблюдение снимком не является: оно лишь дополняет состав.
        $snapshotAccepted = $mapped->itemsAuthoritative && $order->acceptSnapshot($observedAt);

        // Атрибуты сливаются ПО ВЛАДЕЛЬЦУ ОСИ.
        //
        // Статусные меняются во времени, поэтому идут только с принятым
        // статусом: иначе более старый, но первый полный снимок записал бы
        // свои supplier_status и wb_status поверх свежей отмены — заказ
        // показывал бы актуальный CANCELLED рядом с устаревшими осями.
        // Снимочные описывают заказ как таковой и идут со снимком.
        if ($statusAccepted) {
            $order->mergeAttributes($mapped->statusAttributes);
        }

        if ($snapshotAccepted) {
            $order->mergeAttributes($mapped->attributes);
        }

        // Номер заказа задаёт принятый авторитетный снимок. Заполнить пустое
        // можно и из отклонённого наблюдения: номер во времени не меняется, а
        // пустое поле хуже старого значения.
        if (null !== $mapped->externalOrderId && ($snapshotAccepted || null === $order->getExternalOrderId())) {
            $order->setExternalOrderId($mapped->externalOrderId);
        }

        if (!$mapped->itemsAuthoritative) {
            // Частичное наблюдение дополняет состав только если оно ПРИНЯТО.
            //
            // Оно не переписывает существующие позиции, но создаёт
            // недостающие, а ключ позиции у WB зависит от артикула: старое
            // наблюдение с изменившимся артикулом добавило бы вторую позицию
            // к тому же заказу. Отклонённое по времени сырьё состава касаться
            // не должно вовсе.
            // У частичного наблюдения СВОЯ отметка свежести.
            //
            // Привязать его к статусной нельзя: поток изменений WB при
            // `isCancel = false` статуса не несёт вовсе, и тогда все его
            // непротиворечивые данные — сумма, склад, уточнение схемы,
            // недостающая позиция — терялись бы навсегда.
            if (!$order->acceptPartialObservation($observedAt)) {
                return $order;
            }

            // Атрибуты частичного потока применяются по ЕГО отметке: они
            // описывают то, что видит именно он. Статусные остаются за
            // статусной осью — их пишет только принятое статусное наблюдение.
            $order->mergeAttributes($mapped->attributes);

            // Схему частичное наблюдение уточняет, но не портит: заказ,
            // впервые пришедший с неизвестным типом склада, иначе навсегда
            // остался бы UNKNOWN — авторитетного снимка для него может не
            // быть вовсе (заказы FBO в marketplace-поток не попадают).
            if (IngestOrderScheme::UNKNOWN !== $mapped->scheme && IngestOrderScheme::UNKNOWN === $order->getScheme()) {
                $order->applyScheme($mapped->scheme);
            }

            // Позицию частичное наблюдение добавляет только если оно не
            // старше последнего полного снимка.
            //
            // Снимок описывает состав заказа целиком, поэтому наблюдение,
            // сделанное до него, не может знать о позиции, которой в снимке
            // нет: у WB ключ позиции зависит от артикула, и переименование
            // в старой строке иначе добавило бы к заказу фантомную вторую
            // позицию.
            $snapshotAt = $order->getSnapshotObservedAt();
            if (null === $snapshotAt || $observedAt >= $snapshotAt) {
                $applied = $this->applyItems(
                    $order,
                    $mapped,
                    $knownItems[$order->getId()] ?? [],
                    $resolutions,
                    $orderIndex,
                );
                $knownItems[$order->getId()] = $applied + ($knownItems[$order->getId()] ?? []);
            }

            return $order;
        }

        if (!$snapshotAccepted) {
            return $order;
        }

        // Схему задаёт принятый авторитетный снимок — но UNKNOWN не затирает
        // уже известное значение: «не знаю» не является уточнением.
        if (IngestOrderScheme::UNKNOWN !== $mapped->scheme || IngestOrderScheme::UNKNOWN === $order->getScheme()) {
            $order->applyScheme($mapped->scheme);
        }

        $applied = $this->applyItems(
            $order,
            $mapped,
            $knownItems[$order->getId()] ?? [],
            $resolutions,
            $orderIndex,
        );

        // Пул пополняется, но не сжимается: позиция, пропавшая из этого
        // снимка, может вернуться следующим снимком того же батча, и тогда
        // её нужно переиспользовать, а не создавать заново.
        $knownItems[$order->getId()] = $applied + ($knownItems[$order->getId()] ?? []);

        // Вычистка исчезнувших позиций опирается ТОЛЬКО на полные снимки.
        // Частичное наблюдение сюда не доходит: оно вернулось выше.
        $currentItems[$order->getId()] = $applied;

        return $order;
    }

    /**
     * Возвращает набор позиций ЭТОГО снимка (не объединённый с прежними):
     * вызывающий по нему решает, какие старые позиции удалить после батча.
     *
     * @param array<string, IngestOrderItem> $existing lineKey => позиция
     * @param array<array-key, ListingResolution|null> $resolutions «индекс заказа:индекс позиции»
     *
     * @return array<string, IngestOrderItem>
     */
    private function applyItems(
        IngestOrder $order,
        MappedOrder $mapped,
        array $existing,
        array $resolutions,
        int $orderIndex,
    ): array {
        $mappedItems = $mapped->items;
        $applied = [];

        foreach ($mappedItems as $index => $mappedItem) {
            $item = $existing[$mappedItem->lineKey] ?? null;

            $isNew = null === $item;

            if (null === $item) {
                $item = new IngestOrderItem(
                    companyId: $order->getCompanyId(),
                    orderId: $order->getId(),
                    lineNo: $mappedItem->lineNo,
                    lineKey: $mappedItem->lineKey,
                    quantity: $mappedItem->quantity,
                    externalSku: $mappedItem->externalSku,
                    offerId: $mappedItem->offerId,
                    barcode: $mappedItem->barcode,
                    name: $mappedItem->name,
                    priceMinor: $mappedItem->priceMinor,
                    currency: $mappedItem->currency,
                    marketplaceBuyout: $mappedItem->marketplaceBuyout,
                );
                $this->itemRepository->save($item);
            } elseif ($mapped->itemsAuthoritative) {
                // Частичное наблюдение существующую позицию не трогает: оно
                // знает о заказе не всё, и его цена может иметь другую
                // семантику (у WB finishedPrice против price маркетплейса).
                $item->refresh(
                    $mappedItem->lineNo,
                    $mappedItem->quantity,
                    $mappedItem->name,
                    $mappedItem->priceMinor,
                    $mappedItem->currency,
                    $mappedItem->marketplaceBuyout,
                );
            }

            // Привязку к листингу переписывает только полный снимок или
            // создание позиции: частичное наблюдение не должно затирать
            // разрешение, полученное из потока, который видит заказ целиком.
            if ($isNew || $mapped->itemsAuthoritative) {
                $resolution = $resolutions[$orderIndex.':'.$index] ?? null;

                // Нерезолвленное не теряется: ключ, по которому пытались
                // искать, сохраняется даже когда листинг не найден — это
                // очередь на разбор, а не молчаливый NULL.
                $item->linkListing(
                    $resolution?->listingId,
                    null === $resolution ? $mappedItem->externalSku : $resolution->listingSku,
                );
            }

            $applied[$mappedItem->lineKey] = $item;
        }

        // Удаление исчезнувших позиций здесь НЕ делается: тот же заказ может
        // встретиться в батче ещё раз, и удалённая сущность понадобится снова.
        // Вычистка — один раз после всего батча, в __invoke().
        return $applied;
    }
}
