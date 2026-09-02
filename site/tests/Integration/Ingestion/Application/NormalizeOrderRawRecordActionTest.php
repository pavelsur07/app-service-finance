<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application;

use App\Ingestion\Application\Action\NormalizeOrderRawRecordAction;
use App\Ingestion\Application\Command\NormalizeRawRecordCommand;
use App\Ingestion\Application\DTO\MappedOrder;
use App\Ingestion\Application\DTO\MappedOrderItem;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Enum\IngestOrderScheme;
use App\Ingestion\Enum\IngestOrderStatus;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Repository\IngestOrderItemRepository;
use App\Ingestion\Repository\IngestOrderRepository;
use App\Ingestion\Repository\IngestOrderStatusEventRepository;
use App\Tests\Integration\Ingestion\Fixtures\FakeOrderMapper;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class NormalizeOrderRawRecordActionTest extends IntegrationTestCase
{
    private const CONNECTION_REF = 'connection-1';

    private string $companyId;
    private FakeOrderMapper $mapper;
    private NormalizeOrderRawRecordAction $action;
    private IngestOrderRepository $orders;
    private IngestOrderItemRepository $items;
    private IngestOrderStatusEventRepository $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = Uuid::uuid7()->toString();
        $this->mapper = self::getContainer()->get(FakeOrderMapper::class);
        $this->action = self::getContainer()->get(NormalizeOrderRawRecordAction::class);
        $this->orders = self::getContainer()->get(IngestOrderRepository::class);
        $this->items = self::getContainer()->get(IngestOrderItemRepository::class);
        $this->events = self::getContainer()->get(IngestOrderStatusEventRepository::class);
    }

    public function testCreatesOrderWithItemsAndOpeningStatusEvent(): void
    {
        $this->mapper->queue($this->order('delivering', 2));
        $rawId = $this->storeRaw();

        ($this->action)(new NormalizeRawRecordCommand($rawId, $this->companyId));

        $order = $this->orders->findByExternalId($this->companyId, IngestSource::OZON, self::CONNECTION_REF, 'posting-1');
        self::assertNotNull($order);
        self::assertSame(IngestOrderStatus::SHIPPED, $order->getStatus());
        self::assertCount(2, $this->items->findByOrderIndexedByLineKey($this->companyId, $order->getId()));
        self::assertSame(1, $this->events->countByOrder($this->companyId, $order->getId()));
    }

    /**
     * Перенормализация того же сырья не должна плодить ни позиции, ни события.
     * Ключ идемпотентности позиций — (company, order, lineKey).
     */
    public function testRenormalizingSameRawCreatesNoDuplicates(): void
    {
        $this->mapper->queue($this->order('delivering', 2));
        $rawId = $this->storeRaw();

        ($this->action)(new NormalizeRawRecordCommand($rawId, $this->companyId));
        ($this->action)(new NormalizeRawRecordCommand($rawId, $this->companyId, forceReplay: true));

        $order = $this->orders->findByExternalId($this->companyId, IngestSource::OZON, self::CONNECTION_REF, 'posting-1');
        self::assertNotNull($order);
        self::assertCount(2, $this->items->findByOrderIndexedByLineKey($this->companyId, $order->getId()));
        self::assertSame(1, $this->events->countByOrder($this->companyId, $order->getId()));
    }

    /**
     * Часовой опрос присылает один и тот же статус снова и снова: событие
     * пишется только на смену, иначе журнал рос бы на 24 строки в сутки на
     * каждый заказ.
     */
    public function testStatusChangeAppendsSecondEvent(): void
    {
        $this->mapper->queue($this->order('delivering', 1));
        ($this->action)(new NormalizeRawRecordCommand($this->storeRaw('page-1'), $this->companyId));

        $this->mapper->queue($this->order('delivered', 1));
        ($this->action)(new NormalizeRawRecordCommand($this->storeRaw('page-2', new \DateTimeImmutable('+1 hour')), $this->companyId));

        $order = $this->orders->findByExternalId($this->companyId, IngestSource::OZON, self::CONNECTION_REF, 'posting-1');
        self::assertNotNull($order);
        self::assertSame(IngestOrderStatus::DELIVERED, $order->getStatus());
        self::assertSame(2, $this->events->countByOrder($this->companyId, $order->getId()));
    }

    /**
     * Устаревшее наблюдение — факт, и оно фиксируется. Но текущее состояние
     * заказа назад не едет: доставленный заказ не должен снова стать «в пути».
     */
    public function testStaleObservationIsRecordedButDoesNotRewindStatus(): void
    {
        $this->mapper->queue($this->order('delivered', 1));
        ($this->action)(new NormalizeRawRecordCommand($this->storeRaw('page-1', new \DateTimeImmutable('+1 hour')), $this->companyId));

        $this->mapper->queue($this->order('delivering', 1));
        ($this->action)(new NormalizeRawRecordCommand($this->storeRaw('page-2', new \DateTimeImmutable('-1 hour')), $this->companyId));

        $order = $this->orders->findByExternalId($this->companyId, IngestSource::OZON, self::CONNECTION_REF, 'posting-1');
        self::assertNotNull($order);
        self::assertSame(IngestOrderStatus::DELIVERED, $order->getStatus(), 'Статус не должен ехать назад.');
        self::assertSame(2, $this->events->countByOrder($this->companyId, $order->getId()), 'Наблюдение — факт, оно фиксируется.');

        // ...но переходом оно не является. Запись с previousStatus = DELIVERED
        // и status = SHIPPED утверждала бы движение заказа, которого не было.
        $stale = $this->connection->fetchAssociative(
            'SELECT applied, previous_status FROM ingest_order_status_events
             WHERE company_id = :c AND order_id = :o AND raw_status = :s',
            ['c' => $this->companyId, 'o' => $order->getId(), 's' => 'delivering'],
        );

        self::assertIsArray($stale);
        self::assertFalse((bool) $stale['applied'], 'Устаревшее наблюдение состояние не сдвинуло.');
        self::assertNull($stale['previous_status'], 'У неприменённого наблюдения перехода нет.');
    }

    /**
     * Регрессия: устаревшее наблюдение не двигает статус заказа, поэтому
     * «статус отличается от текущего» остаётся истиной навсегда. На старом
     * коде каждый повторный прогон того же сырья дописывал ещё одну копию
     * события, и журнал рос при полном отсутствии новых данных.
     */
    public function testReplayingStaleObservationDoesNotDuplicateItsEvent(): void
    {
        $this->mapper->queue($this->order('delivered', 1));
        ($this->action)(new NormalizeRawRecordCommand($this->storeRaw('page-1', new \DateTimeImmutable('+1 hour')), $this->companyId));

        $this->mapper->queue($this->order('delivering', 1));
        $staleRawId = $this->storeRaw('page-2', new \DateTimeImmutable('-1 hour'));
        ($this->action)(new NormalizeRawRecordCommand($staleRawId, $this->companyId));
        ($this->action)(new NormalizeRawRecordCommand($staleRawId, $this->companyId, forceReplay: true));
        ($this->action)(new NormalizeRawRecordCommand($staleRawId, $this->companyId, forceReplay: true));

        $order = $this->orders->findByExternalId($this->companyId, IngestSource::OZON, self::CONNECTION_REF, 'posting-1');
        self::assertNotNull($order);
        self::assertSame(IngestOrderStatus::DELIVERED, $order->getStatus());
        self::assertSame(2, $this->events->countByOrder($this->companyId, $order->getId()));
    }

    /**
     * Регрессия: повтор вёл себя по-разному в зависимости от момента.
     *
     * Сырьё, впервые разобранное при том же статусе, события не давало —
     * записывать было нечего. Но ключ подавления брался из уже записанных
     * событий, поэтому после следующей смены статуса тот же повтор внезапно
     * находил отличие, ключа не находил и дописывал событие с
     * `previousStatus` из будущего: перевёрнутый переход, появившийся из
     * ничего. На старом коде журнал рос от каждого повтора.
     */
    public function testReplayOfRawSeenAtTheSameStatusStaysSilentAfterStatusMoves(): void
    {
        // Заказ создан первым сырьём — у него есть открывающее событие.
        $this->mapper->queue($this->order('delivering', 1));
        ($this->action)(new NormalizeRawRecordCommand($this->storeRaw('page-1', new \DateTimeImmutable('-3 hours')), $this->companyId));

        $order = $this->orders->findByExternalId($this->companyId, IngestSource::OZON, self::CONNECTION_REF, 'posting-1');
        self::assertNotNull($order);

        // Второе сырьё подтверждает ТОТ ЖЕ статус: событий не появляется, и
        // ключа подавления для этого сырья тоже не остаётся — именно здесь
        // старый код и ломался.
        $this->mapper->queue($this->order('delivering', 1));
        $confirmingRawId = $this->storeRaw('page-2', new \DateTimeImmutable('-2 hours'));
        ($this->action)(new NormalizeRawRecordCommand($confirmingRawId, $this->companyId));

        $afterConfirmation = $this->events->countByOrder($this->companyId, $order->getId());
        self::assertSame(1, $afterConfirmation, 'Подтверждение того же статуса события не даёт.');

        // Статус уехал вперёд.
        $this->mapper->queue($this->order('delivered', 1));
        ($this->action)(new NormalizeRawRecordCommand($this->storeRaw('page-3', new \DateTimeImmutable('-1 hour')), $this->companyId));

        self::assertSame(2, $this->events->countByOrder($this->companyId, $order->getId()));

        // Повтор подтверждающего сырья наблюдением не является. На старом коде
        // он находил отличие, ключа не находил и дописывал событие
        // `DELIVERED → DELIVERING`: перевёрнутый переход из ничего.
        $this->mapper->queue($this->order('delivering', 1));
        ($this->action)(new NormalizeRawRecordCommand($confirmingRawId, $this->companyId, forceReplay: true));

        self::assertSame(2, $this->events->countByOrder($this->companyId, $order->getId()));

        $this->em->clear();
        $reloaded = $this->orders->findByExternalId($this->companyId, IngestSource::OZON, self::CONNECTION_REF, 'posting-1');
        self::assertNotNull($reloaded);
        self::assertSame(IngestOrderStatus::DELIVERED, $reloaded->getStatus(), 'Повтор старого сырья не откатывает статус.');
    }

    /**
     * Регрессия: идентичность позиции была позиционной. Источник вправе
     * прислать те же товары в другом порядке — на старом коде строка 0
     * сохраняла прежние externalSku/offerId, но получала количество, цену и
     * листинг соседнего товара. Прямое смешение данных между позициями.
     */
    public function testReorderedItemsKeepTheirOwnData(): void
    {
        $first = new MappedOrderItem(lineNo: 0, lineKey: 'sku:AAA#0', quantity: 1, externalSku: 'AAA', priceMinor: '10000');
        $second = new MappedOrderItem(lineNo: 1, lineKey: 'sku:BBB#0', quantity: 7, externalSku: 'BBB', priceMinor: '90000');

        $this->mapper->queue($this->orderWithItems('delivering', [$first, $second]));
        ($this->action)(new NormalizeRawRecordCommand($this->storeRaw('page-1'), $this->companyId));

        // Тот же заказ, те же товары — переставлены местами.
        $this->mapper->queue($this->orderWithItems('delivering', [
            new MappedOrderItem(lineNo: 0, lineKey: 'sku:BBB#0', quantity: 7, externalSku: 'BBB', priceMinor: '90000'),
            new MappedOrderItem(lineNo: 1, lineKey: 'sku:AAA#0', quantity: 1, externalSku: 'AAA', priceMinor: '10000'),
        ]));
        ($this->action)(new NormalizeRawRecordCommand($this->storeRaw('page-2', new \DateTimeImmutable('+1 hour')), $this->companyId));

        $order = $this->orders->findByExternalId($this->companyId, IngestSource::OZON, self::CONNECTION_REF, 'posting-1');
        self::assertNotNull($order);

        $items = $this->items->findByOrderIndexedByLineKey($this->companyId, $order->getId());
        self::assertCount(2, $items, 'Перестановка не должна создавать новые позиции.');

        // Количество и цена остались при своих SKU, а не переехали к соседу.
        self::assertSame('AAA', $items['sku:AAA#0']->getExternalSku());
        self::assertSame(1, $items['sku:AAA#0']->getQuantity());
        self::assertSame('BBB', $items['sku:BBB#0']->getExternalSku());
        self::assertSame(7, $items['sku:BBB#0']->getQuantity());

        // Порядок отображения при этом обновился.
        self::assertSame(0, $items['sku:BBB#0']->getLineNo());
        self::assertSame(1, $items['sku:AAA#0']->getLineNo());
    }

    /**
     * Регрессия: результат observeStatus() игнорировался. Устаревшее сырьё не
     * трогало статус, но всё равно откатывало количество, цену и атрибуты —
     * получался внутренне противоречивый заказ: новый статус с данными из
     * старого ответа.
     */
    public function testStaleObservationDoesNotRewindItemData(): void
    {
        $fresh = new MappedOrderItem(lineNo: 0, lineKey: 'sku:AAA#0', quantity: 5, externalSku: 'AAA', priceMinor: '90000');
        $this->mapper->queue($this->orderWithItems('delivered', [$fresh]));
        ($this->action)(new NormalizeRawRecordCommand($this->storeRaw('page-1', new \DateTimeImmutable('+1 hour')), $this->companyId));

        // Старое сырьё: другой статус И другие данные позиции.
        $stale = new MappedOrderItem(lineNo: 0, lineKey: 'sku:AAA#0', quantity: 1, externalSku: 'AAA', priceMinor: '10000');
        $this->mapper->queue($this->orderWithItems('delivering', [$stale]));
        ($this->action)(new NormalizeRawRecordCommand($this->storeRaw('page-2', new \DateTimeImmutable('-1 hour')), $this->companyId));

        $order = $this->orders->findByExternalId($this->companyId, IngestSource::OZON, self::CONNECTION_REF, 'posting-1');
        self::assertNotNull($order);
        self::assertSame(IngestOrderStatus::DELIVERED, $order->getStatus());

        $items = $this->items->findByOrderIndexedByLineKey($this->companyId, $order->getId());
        self::assertSame(5, $items['sku:AAA#0']->getQuantity(), 'Количество не должно откатываться старым сырьём.');
        self::assertSame('90000', $items['sku:AAA#0']->getPriceMinor(), 'Цена не должна откатываться старым сырьём.');

        // Событие при этом фиксируется: наблюдение — факт, который был.
        self::assertSame(2, $this->events->countByOrder($this->companyId, $order->getId()));
    }

    /**
     * Батч с несколькими заказами обрабатывается целиком, и повторяющийся
     * externalId внутри одного батча попадает в ту же запись, а не создаёт
     * вторую и не упирается в уникальный индекс.
     */
    public function testBatchWithRepeatedExternalIdCreatesSingleOrder(): void
    {
        $this->mapper->queue(
            $this->orderWithItems('delivering', [new MappedOrderItem(lineNo: 0, lineKey: 'sku:AAA#0', quantity: 1)]),
            $this->orderWithItems('delivering', [new MappedOrderItem(lineNo: 0, lineKey: 'sku:AAA#0', quantity: 2)]),
        );

        ($this->action)(new NormalizeRawRecordCommand($this->storeRaw('page-1'), $this->companyId));

        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ingest_orders WHERE company_id = :c',
            ['c' => $this->companyId],
        ));
    }

    /**
     * Регрессия: existsObservation() спрашивала БД на каждое событие, а
     * Doctrine-запрос не видит непрофлашенные сущности. Последовательность
     * A → B → A внутри одного батча создавала два события A и роняла финальный
     * flush на уникальном индексе.
     */
    public function testRepeatedStatusWithinOneBatchDoesNotDuplicateEvent(): void
    {
        $this->mapper->queue(
            $this->orderWithItems('delivering', [new MappedOrderItem(lineNo: 0, lineKey: 'sku:AAA#0', quantity: 1)]),
            $this->orderWithItems('delivered', [new MappedOrderItem(lineNo: 0, lineKey: 'sku:AAA#0', quantity: 1)]),
            $this->orderWithItems('delivering', [new MappedOrderItem(lineNo: 0, lineKey: 'sku:AAA#0', quantity: 1)]),
        );

        ($this->action)(new NormalizeRawRecordCommand($this->storeRaw('page-1'), $this->companyId));

        $order = $this->orders->findByExternalId($this->companyId, IngestSource::OZON, self::CONNECTION_REF, 'posting-1');
        self::assertNotNull($order);
        // delivering и delivered — по одному событию, повтор delivering не пишется.
        self::assertSame(2, $this->events->countByOrder($this->companyId, $order->getId()));
    }

    /**
     * Регрессия: refresh() сохранял прежние nullable-значения через `??`.
     * Источник отдаёт отправление целиком, поэтому отсутствие цены в свежем
     * ответе означает «цены нет», а не «оставь старую» — заказ нёс стоимость
     * из устаревшего ответа.
     */
    public function testFreshSnapshotClearsPriceThatDisappeared(): void
    {
        $withPrice = new MappedOrderItem(lineNo: 0, lineKey: 'sku:AAA#0', quantity: 1, externalSku: 'AAA', priceMinor: '90000');
        $this->mapper->queue($this->orderWithItems('delivering', [$withPrice]));
        ($this->action)(new NormalizeRawRecordCommand($this->storeRaw('page-1'), $this->companyId));

        $withoutPrice = new MappedOrderItem(lineNo: 0, lineKey: 'sku:AAA#0', quantity: 1, externalSku: 'AAA');
        $this->mapper->queue($this->orderWithItems('delivered', [$withoutPrice]));
        ($this->action)(new NormalizeRawRecordCommand($this->storeRaw('page-2', new \DateTimeImmutable('+1 hour')), $this->companyId));

        $order = $this->orders->findByExternalId($this->companyId, IngestSource::OZON, self::CONNECTION_REF, 'posting-1');
        self::assertNotNull($order);

        $items = $this->items->findByOrderIndexedByLineKey($this->companyId, $order->getId());
        self::assertNull($items['sku:AAA#0']->getPriceMinor());
    }

    /**
     * Позиция, исчезнувшая из свежего снимка, удаляется: оставленная строка
     * завышала бы и сумму заказа, и агрегаты по выкупу.
     */
    public function testItemMissingFromFreshSnapshotIsRemoved(): void
    {
        $this->mapper->queue($this->orderWithItems('delivering', [
            new MappedOrderItem(lineNo: 0, lineKey: 'sku:AAA#0', quantity: 1, externalSku: 'AAA'),
            new MappedOrderItem(lineNo: 1, lineKey: 'sku:BBB#0', quantity: 1, externalSku: 'BBB'),
        ]));
        ($this->action)(new NormalizeRawRecordCommand($this->storeRaw('page-1'), $this->companyId));

        $this->mapper->queue($this->orderWithItems('delivered', [
            new MappedOrderItem(lineNo: 0, lineKey: 'sku:AAA#0', quantity: 1, externalSku: 'AAA'),
        ]));
        ($this->action)(new NormalizeRawRecordCommand($this->storeRaw('page-2', new \DateTimeImmutable('+1 hour')), $this->companyId));

        $order = $this->orders->findByExternalId($this->companyId, IngestSource::OZON, self::CONNECTION_REF, 'posting-1');
        self::assertNotNull($order);

        $items = $this->items->findByOrderIndexedByLineKey($this->companyId, $order->getId());
        self::assertSame(['sku:AAA#0'], array_keys($items));
    }

    /**
     * Регрессия: удаление исчезнувших позиций выполнялось внутри цикла. Если
     * тот же заказ встречался в батче ещё раз со снимком {A,B} после {A},
     * Doctrine выполнял INSERT раньше DELETE и уникальный индекс ронял общий
     * flush. Вычистка перенесена за пределы батча.
     */
    public function testItemReappearingLaterInTheSameBatchSurvives(): void
    {
        $a = static fn (): MappedOrderItem => new MappedOrderItem(lineNo: 0, lineKey: 'sku:AAA#0', quantity: 1, externalSku: 'AAA');
        $b = static fn (): MappedOrderItem => new MappedOrderItem(lineNo: 1, lineKey: 'sku:BBB#0', quantity: 1, externalSku: 'BBB');

        // Заказ уже существует с двумя позициями.
        $this->mapper->queue($this->orderWithItems('delivering', [$a(), $b()]));
        ($this->action)(new NormalizeRawRecordCommand($this->storeRaw('page-1'), $this->companyId));

        // Один raw: сначала снимок {A}, затем снова {A,B}.
        $this->mapper->queue(
            $this->orderWithItems('delivering', [$a()]),
            $this->orderWithItems('delivered', [$a(), $b()]),
        );
        ($this->action)(new NormalizeRawRecordCommand($this->storeRaw('page-2', new \DateTimeImmutable('+1 hour')), $this->companyId));

        $order = $this->orders->findByExternalId($this->companyId, IngestSource::OZON, self::CONNECTION_REF, 'posting-1');
        self::assertNotNull($order);

        $items = $this->items->findByOrderIndexedByLineKey($this->companyId, $order->getId());
        self::assertSame(['sku:AAA#0', 'sku:BBB#0'], $this->sortedKeys($items));
    }

    /**
     * @param array<string, mixed> $items
     *
     * @return list<string>
     */
    private function sortedKeys(array $items): array
    {
        $keys = array_keys($items);
        sort($keys);

        return $keys;
    }

    /**
     * Резолв листингов вынесен из цикла: один вызов на всё сырьё вместо вызова
     * на заказ. Ключ теперь составной — «индекс заказа:индекс позиции», и
     * ошибка в нём привязала бы позицию к чужому листингу. Проверяем, что
     * позиции разных заказов сохранили каждая свой ключ резолва.
     */
    public function testResolutionsStayWithTheirOwnOrdersWhenResolvedInOneBatch(): void
    {
        $this->mapper->queue(
            $this->orderWithExternalId('posting-1', [
                new MappedOrderItem(lineNo: 0, lineKey: 'sku:AAA|offer:#0', quantity: 1, externalSku: 'AAA', sourceData: ['sku' => 'AAA']),
            ]),
            $this->orderWithExternalId('posting-2', [
                new MappedOrderItem(lineNo: 0, lineKey: 'sku:BBB|offer:#0', quantity: 1, externalSku: 'BBB', sourceData: ['sku' => 'BBB']),
                new MappedOrderItem(lineNo: 1, lineKey: 'sku:CCC|offer:#0', quantity: 1, externalSku: 'CCC', sourceData: ['sku' => 'CCC']),
            ]),
        );

        ($this->action)(new NormalizeRawRecordCommand($this->storeRaw('page-1'), $this->companyId));

        $first = $this->orders->findByExternalId($this->companyId, IngestSource::OZON, self::CONNECTION_REF, 'posting-1');
        $second = $this->orders->findByExternalId($this->companyId, IngestSource::OZON, self::CONNECTION_REF, 'posting-2');
        self::assertNotNull($first);
        self::assertNotNull($second);

        $firstItems = $this->items->findByOrderIndexedByLineKey($this->companyId, $first->getId());
        $secondItems = $this->items->findByOrderIndexedByLineKey($this->companyId, $second->getId());

        self::assertSame(['sku:AAA|offer:#0'], array_keys($firstItems));
        self::assertSame(['sku:BBB|offer:#0', 'sku:CCC|offer:#0'], $this->sortedKeys($secondItems));

        // Ключ резолва сохранён у каждой позиции свой — листинг не найден, но
        // именно свой externalSku, а не соседний.
        self::assertSame('AAA', $firstItems['sku:AAA|offer:#0']->getListingSku());
        self::assertSame('BBB', $secondItems['sku:BBB|offer:#0']->getListingSku());
        self::assertSame('CCC', $secondItems['sku:CCC|offer:#0']->getListingSku());
    }

    /**
     * @param list<MappedOrderItem> $items
     */
    private function orderWithExternalId(string $externalId, array $items): MappedOrder
    {
        return new MappedOrder(
            externalId: $externalId,
            scheme: IngestOrderScheme::FBO,
            orderedAt: new \DateTimeImmutable('-2 days'),
            rawStatus: 'delivering',
            items: $items,
        );
    }

    /**
     * Незнакомый статус деградирует в UNKNOWN и попадает в видимую очередь,
     * а не в NULL и не в исключение.
     */
    public function testUnknownStatusBecomesUnknownAndRaisesIssue(): void
    {
        $this->mapper->queue($this->order('какой_то_новый_статус', 1));
        $rawId = $this->storeRaw();

        ($this->action)(new NormalizeRawRecordCommand($rawId, $this->companyId));

        $order = $this->orders->findByExternalId($this->companyId, IngestSource::OZON, self::CONNECTION_REF, 'posting-1');
        self::assertNotNull($order);
        self::assertSame(IngestOrderStatus::UNKNOWN, $order->getStatus());

        self::assertSame(1, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM ingest_normalization_issues WHERE company_id = :c AND kind = 'unknown_order_status'",
            ['c' => $this->companyId],
        ));
    }

    /**
     * @param list<MappedOrderItem> $items
     */
    private function orderWithItems(string $rawStatus, array $items): MappedOrder
    {
        return new MappedOrder(
            externalId: 'posting-1',
            scheme: IngestOrderScheme::FBO,
            orderedAt: new \DateTimeImmutable('-2 days'),
            rawStatus: $rawStatus,
            items: $items,
        );
    }

    private function order(string $rawStatus, int $itemCount): MappedOrder
    {
        $items = [];
        for ($i = 0; $i < $itemCount; ++$i) {
            $items[] = new MappedOrderItem(
                lineNo: $i,
                lineKey: 'sku:70000000'.$i.'#0',
                quantity: 1,
                externalSku: '70000000'.$i,
                offerId: 'TEST-ART-'.$i,
                name: 'Товар '.$i,
                priceMinor: '150000',
                currency: 'RUB',
                sourceData: ['sku' => '70000000'.$i, 'offer_id' => 'TEST-ART-'.$i],
            );
        }

        return new MappedOrder(
            externalId: 'posting-1',
            scheme: IngestOrderScheme::FBO,
            orderedAt: new \DateTimeImmutable('-2 days'),
            rawStatus: $rawStatus,
            items: $items,
        );
    }

    private function storeRaw(string $externalId = 'page-1', ?\DateTimeImmutable $fetchedAt = null): string
    {
        /** @var RawStorageFacade $facade */
        $facade = self::getContainer()->get(RawStorageFacade::class);

        return $facade->storeAndGetIds(new RawBatch(
            companyId: $this->companyId,
            connectionRef: self::CONNECTION_REF,
            shopRef: 'shop-main',
            source: IngestSource::OZON,
            resourceType: FakeOrderMapper::RESOURCE_TYPE,
            externalId: $externalId,
            syncJobId: Uuid::uuid7()->toString(),
            fetchedAt: $fetchedAt ?? new \DateTimeImmutable(),
            rows: [['posting_number' => 'posting-1']],
        ))[0];
    }
}
