<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application\Source\Wildberries;

use App\Ingestion\Application\Action\NormalizeOrderRawRecordAction;
use App\Ingestion\Application\Command\NormalizeRawRecordCommand;
use App\Ingestion\Application\Source\Wildberries\WbResourceType;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Enum\IngestOrderScheme;
use App\Ingestion\Enum\IngestOrderStatus;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Repository\IngestOrderItemRepository;
use App\Ingestion\Repository\IngestOrderRepository;
use App\Ingestion\Repository\IngestOrderStatusEventRepository;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Сшивка двух потоков WB — главное свойство этой стадии, поэтому проверяется
 * сквозняком через настоящий маппер и настоящую нормализацию, а не на фейке.
 */
final class WbOrderJoinTest extends IntegrationTestCase
{
    private const CONNECTION_REF = 'connection-1';
    private const SHARED_RID = 'eTEST.i0000000000000000000000000000001.0.0';
    private const STATISTICS_ONLY_SRID = 'eTEST.i9999999999999999999999999999999.0.0';

    private string $companyId;
    private NormalizeOrderRawRecordAction $action;
    private IngestOrderRepository $orders;
    private IngestOrderItemRepository $items;
    private IngestOrderStatusEventRepository $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = Uuid::uuid7()->toString();
        $this->action = self::getContainer()->get(NormalizeOrderRawRecordAction::class);
        $this->orders = self::getContainer()->get(IngestOrderRepository::class);
        $this->items = self::getContainer()->get(IngestOrderItemRepository::class);
        $this->events = self::getContainer()->get(IngestOrderStatusEventRepository::class);
    }

    /**
     * Заказ, пришедший из обоих потоков, обязан дать ОДНУ запись, а не две:
     * ради этого `externalId` берётся из `rid` и `srid`, которые у WB
     * совпадают.
     */
    public function testOrderFromBothFeedsBecomesASingleRecord(): void
    {
        $this->normalizeMarketplace(new \DateTimeImmutable('-2 hours'));
        $this->normalizeStatistics(new \DateTimeImmutable('-1 hour'));

        self::assertSame(1, $this->orderCount(self::SHARED_RID));

        $order = $this->orders->findByExternalId($this->companyId, IngestSource::WILDBERRIES, self::CONNECTION_REF, self::SHARED_RID);
        self::assertNotNull($order);

        // Позиция тоже одна: ключ позиции строится из той же пары
        // идентификаторов, поэтому второй поток обновляет, а не дублирует.
        self::assertCount(1, $this->items->findByOrderIndexedByLineKey($this->companyId, $order->getId()));
    }

    /**
     * Порядок прихода потоков не должен менять результат: statistics может
     * обогнать marketplace, потому что это поток изменений.
     */
    public function testJoinIsIndependentOfFeedOrder(): void
    {
        $this->normalizeStatistics(new \DateTimeImmutable('-2 hours'));
        $this->normalizeMarketplace(new \DateTimeImmutable('-1 hour'));

        self::assertSame(1, $this->orderCount(self::SHARED_RID));

        $order = $this->orders->findByExternalId($this->companyId, IngestSource::WILDBERRIES, self::CONNECTION_REF, self::SHARED_RID);
        self::assertNotNull($order);

        // Свежее наблюдение — marketplace, и статус взят из него.
        self::assertSame(IngestOrderStatus::SHIPPED, $order->getStatus());
        self::assertSame('supplierStatus=complete;wbStatus=sorted', $order->getRawStatus());
    }

    /**
     * Заказ, которого в marketplace нет вовсе, всё равно должен появиться:
     * ради этого statistics и нужен — он приносит отмены задним числом.
     */
    public function testStatisticsOnlyOrderIsStoredAsCancelled(): void
    {
        $this->normalizeMarketplace(new \DateTimeImmutable('-2 hours'));
        $this->normalizeStatistics(new \DateTimeImmutable('-1 hour'));

        $order = $this->orders->findByExternalId(
            $this->companyId,
            IngestSource::WILDBERRIES,
            self::CONNECTION_REF,
            self::STATISTICS_ONLY_SRID,
        );

        self::assertNotNull($order);
        self::assertSame(IngestOrderStatus::CANCELLED, $order->getStatus());
        // У этой строки выгрузки warehouseType = «Склад WB», то есть поставка
        // со склада маркетплейса.
        self::assertSame(IngestOrderScheme::FBO, $order->getScheme());
    }

    /**
     * Отмена из statistics обязана перебить более раннее «отгружено» из
     * marketplace: иначе выкуп считался бы по заказу, которого нет.
     */
    public function testLaterCancellationOverridesEarlierShippedStatus(): void
    {
        $this->normalizeMarketplace(new \DateTimeImmutable('-2 hours'));

        // Тот же заказ, но statistics сообщает об отмене.
        $this->normalizeStatistics(new \DateTimeImmutable('-1 hour'), cancelShared: true);

        $order = $this->orders->findByExternalId($this->companyId, IngestSource::WILDBERRIES, self::CONNECTION_REF, self::SHARED_RID);
        self::assertNotNull($order);
        self::assertSame(IngestOrderStatus::CANCELLED, $order->getStatus());

        // Смена статуса — вторая строка журнала, а не переписанная первая.
        self::assertSame(2, $this->events->countByOrder($this->companyId, $order->getId()));
    }

    /**
     * Оба потока обязаны дать заказу один и тот же момент времени: statistics
     * отдаёт московское время без зоны, marketplace — UTC с Z. Расхождение
     * означало бы, что заказ «создан» дважды с разницей в три часа.
     */
    public function testOrderedAtDoesNotDependOnWhichFeedCreatedTheRecord(): void
    {
        $this->normalizeMarketplace(new \DateTimeImmutable('-2 hours'));
        $createdByMarketplace = $this->orders
            ->findByExternalId($this->companyId, IngestSource::WILDBERRIES, self::CONNECTION_REF, self::SHARED_RID)
            ?->getOrderedAt();

        // Тот же заказ у другой компании, но запись создаёт statistics.
        $this->companyId = Uuid::uuid7()->toString();
        $this->normalizeStatistics(new \DateTimeImmutable('-2 hours'));
        $createdByStatistics = $this->orders
            ->findByExternalId($this->companyId, IngestSource::WILDBERRIES, self::CONNECTION_REF, self::SHARED_RID)
            ?->getOrderedAt();

        self::assertNotNull($createdByMarketplace);
        self::assertNotNull($createdByStatistics);

        // Сравниваем АБСОЛЮТНЫЙ момент, а не подпись зоны: хранилище
        // безразлично к зоне, и `22:18:04+03:00` — тот же момент, что и
        // `19:18:04Z`. Сравнение форматированных строк проверяло бы
        // соглашение о записи, а не корректность данных.
        self::assertSame(
            (new \DateTimeImmutable('2026-08-30T19:18:04+00:00'))->getTimestamp(),
            $createdByMarketplace->getTimestamp(),
        );
        self::assertSame($createdByMarketplace->getTimestamp(), $createdByStatistics->getTimestamp());
    }

    /**
     * Регрессия: частичное наблюдение из statistics перезаписывало снимок
     * заказа. Оно подставляло gNumber вместо номера заказа WB и finishedPrice
     * вместо цены маркетплейса — величину с другой семантикой. Снимок начинал
     * зависеть от того, какой поток пришёл последним.
     */
    public function testStatisticsDoesNotOverwriteMarketplaceSnapshot(): void
    {
        $this->normalizeMarketplace(new \DateTimeImmutable('-2 hours'));

        $order = $this->orders->findByExternalId($this->companyId, IngestSource::WILDBERRIES, self::CONNECTION_REF, self::SHARED_RID);
        self::assertNotNull($order);
        $before = $this->items->findByOrderIndexedByLineKey($this->companyId, $order->getId());
        $lineKey = array_key_first($before);
        $priceFromMarketplace = $before[$lineKey]->getPriceMinor();
        $currencyFromMarketplace = $before[$lineKey]->getCurrency();

        // Более позднее наблюдение из statistics — и оно принимается.
        $this->normalizeStatistics(new \DateTimeImmutable('-1 hour'));

        $this->em->clear();
        $order = $this->orders->findByExternalId($this->companyId, IngestSource::WILDBERRIES, self::CONNECTION_REF, self::SHARED_RID);
        self::assertNotNull($order);

        // Номер заказа WB остался, gNumber в него не пролез.
        self::assertSame('5000000001', $order->getExternalOrderId());

        $after = $this->items->findByOrderIndexedByLineKey($this->companyId, $order->getId());
        self::assertSame($priceFromMarketplace, $after[$lineKey]->getPriceMinor(), 'Цена marketplace не должна подменяться finishedPrice.');
        self::assertSame($currencyFromMarketplace, $after[$lineKey]->getCurrency(), 'Валюта marketplace не должна обнуляться.');
    }

    /**
     * Регрессия: свежесть статуса и свежесть снимка были одной отметкой.
     *
     * Потоки приходят вперемешку: statistics могло быть скачано ПОЗЖЕ, а
     * разобрано РАНЬШЕ полного снимка marketplace. Тогда marketplace
     * отклонялся как устаревший целиком, и заказ навсегда оставался без
     * номера, без валюты и с ценой другой семантики. Сценарий строго
     * последовательный, гонки для него не нужно.
     */
    public function testOlderMarketplaceSnapshotStillFillsTheOrderAfterNewerStatistics(): void
    {
        // Сначала разбирается БОЛЕЕ СВЕЖЕЕ частичное наблюдение.
        $this->normalizeStatistics(new \DateTimeImmutable('-1 hour'));

        // Затем — более старый, но авторитетный снимок.
        $this->normalizeMarketplace(new \DateTimeImmutable('-2 hours'));

        $this->em->clear();
        $order = $this->orders->findByExternalId($this->companyId, IngestSource::WILDBERRIES, self::CONNECTION_REF, self::SHARED_RID);
        self::assertNotNull($order);

        // Статус приходит из marketplace, хотя тот и старше: наблюдение
        // statistics с isCancel=false статуса не несло вовсе, поэтому
        // статусной отметки не поставило и дорогу первому настоящему статусу
        // не закрыло.
        self::assertSame('supplierStatus=complete;wbStatus=sorted', $order->getRawStatus());

        // Но авторитетные поля применились.
        self::assertSame('5000000001', $order->getExternalOrderId());

        $items = $this->items->findByOrderIndexedByLineKey($this->companyId, $order->getId());
        $item = $items[array_key_first($items)];
        self::assertSame('195700', $item->getPriceMinor(), 'Цена обязана быть из marketplace.');
        self::assertSame('RUB', $item->getCurrency(), 'Валюта обязана быть из marketplace.');
    }

    /**
     * Обратная сторона: устаревший полный снимок не должен переписывать более
     * свежий полный снимок того же потока.
     */
    public function testOlderMarketplaceSnapshotDoesNotOverwriteNewerOne(): void
    {
        $this->normalizeMarketplace(new \DateTimeImmutable('-1 hour'));

        $stale = $this->marketplaceRows();
        foreach ($stale as $index => $row) {
            if (self::SHARED_RID === ($row['rid'] ?? null)) {
                $stale[$index]['price'] = 111100;
            }
        }
        $rawId = $this->storeRaw(
            WbResourceType::ORDERS_MARKETPLACE,
            'marketplace-stale',
            $stale,
            new \DateTimeImmutable('-3 hours'),
        );
        $this->normalize($rawId);

        $this->em->clear();
        $order = $this->orders->findByExternalId($this->companyId, IngestSource::WILDBERRIES, self::CONNECTION_REF, self::SHARED_RID);
        self::assertNotNull($order);

        $items = $this->items->findByOrderIndexedByLineKey($this->companyId, $order->getId());
        self::assertSame('195700', $items[array_key_first($items)]->getPriceMinor());
    }

    /**
     * Регрессия: атрибуты сливались безусловно. Устаревшее сырьё, не сдвинув
     * статус, всё равно переписывало изменяемые значения — заказ оставался
     * CANCELLED с атрибутом is_cancel=false.
     */
    public function testStaleObservationDoesNotRollBackMutableAttributes(): void
    {
        $this->normalizeStatistics(new \DateTimeImmutable('-1 hour'), cancelShared: true);

        // Более старое наблюдение того же потока говорит «не отменён».
        $this->normalizeStatistics(new \DateTimeImmutable('-3 hours'));

        $this->em->clear();
        $order = $this->orders->findByExternalId($this->companyId, IngestSource::WILDBERRIES, self::CONNECTION_REF, self::SHARED_RID);
        self::assertNotNull($order);

        self::assertSame(IngestOrderStatus::CANCELLED, $order->getStatus());
        self::assertTrue($order->getAttributes()['is_cancel'] ?? null, 'Атрибут не должен откатываться устаревшим наблюдением.');
    }

    /**
     * Регрессия: схема заказа не обновлялась авторитетным снимком. Заказ,
     * созданный частичным наблюдением с незнакомым типом склада, навсегда
     * оставался UNKNOWN, и результат зависел от порядка потоков.
     */
    public function testAuthoritativeSnapshotFixesUnknownSchemeLeftByStatistics(): void
    {
        // statistics с незнакомым типом склада создаёт заказ первым.
        $rows = $this->statisticsRows();
        foreach ($rows as $index => $row) {
            if (self::SHARED_RID === ($row['srid'] ?? null)) {
                $rows[$index]['warehouseType'] = 'Склад будущего';
            }
        }
        $rawId = $this->storeRaw(
            WbResourceType::ORDERS_STATISTICS,
            'statistics-unknown-warehouse',
            $rows,
            new \DateTimeImmutable('-3 hours'),
        );
        $this->normalize($rawId);

        $this->em->clear();
        $order = $this->orders->findByExternalId($this->companyId, IngestSource::WILDBERRIES, self::CONNECTION_REF, self::SHARED_RID);
        self::assertNotNull($order);
        self::assertSame(IngestOrderScheme::UNKNOWN, $order->getScheme(), 'Незнакомый склад не должен угадываться.');

        // Поток, который видит заказ целиком, обязан это исправить.
        $this->normalizeMarketplace(new \DateTimeImmutable('-2 hours'));

        $this->em->clear();
        $order = $this->orders->findByExternalId($this->companyId, IngestSource::WILDBERRIES, self::CONNECTION_REF, self::SHARED_RID);
        self::assertNotNull($order);
        self::assertSame(IngestOrderScheme::FBS, $order->getScheme());
    }

    /**
     * Заказ, которого в marketplace нет, остаётся без цены позиции — но его
     * сумма из statistics сохраняется под собственным именем и обновляется
     * следующими наблюдениями того же потока.
     */
    public function testStatisticsOnlyOrderKeepsFinishedPriceInAttributes(): void
    {
        $this->normalizeStatistics(new \DateTimeImmutable('-2 hours'));

        $order = $this->orders->findByExternalId(
            $this->companyId,
            IngestSource::WILDBERRIES,
            self::CONNECTION_REF,
            self::STATISTICS_ONLY_SRID,
        );
        self::assertNotNull($order);

        $items = $this->items->findByOrderIndexedByLineKey($this->companyId, $order->getId());
        self::assertNull($items[array_key_first($items)]->getPriceMinor(), 'Цена позиции принадлежит marketplace.');
        self::assertSame('138100', $order->getAttributes()['finished_price_minor'] ?? null);
    }

    /**
     * Регрессия: отклонённое по времени частичное наблюдение всё равно могло
     * добавить позицию. Ключ позиции у WB зависит от артикула, поэтому старое
     * наблюдение с изменившимся артикулом создавало вторую строку у того же
     * заказа.
     */
    public function testStaleStatisticsObservationCannotAddAnItem(): void
    {
        $this->normalizeMarketplace(new \DateTimeImmutable('-1 hour'));

        $order = $this->orders->findByExternalId($this->companyId, IngestSource::WILDBERRIES, self::CONNECTION_REF, self::SHARED_RID);
        self::assertNotNull($order);
        self::assertCount(1, $this->items->findByOrderIndexedByLineKey($this->companyId, $order->getId()));

        // Более старое наблюдение с другим артикулом — другой lineKey.
        $rows = $this->statisticsRows();
        foreach ($rows as $index => $row) {
            if (self::SHARED_RID === ($row['srid'] ?? null)) {
                $rows[$index]['supplierArticle'] = 'TEST-ART-RENAMED';
            }
        }
        $rawId = $this->storeRaw(
            WbResourceType::ORDERS_STATISTICS,
            'statistics-stale-renamed',
            $rows,
            new \DateTimeImmutable('-3 hours'),
        );
        $this->normalize($rawId);

        $this->em->clear();
        self::assertCount(
            1,
            $this->items->findByOrderIndexedByLineKey($this->companyId, $order->getId()),
            'Устаревшее наблюдение не должно менять состав заказа.',
        );
    }

    /**
     * Регрессия: снимочные и статусные атрибуты сливались одним условием.
     *
     * В сценарии «свежая отмена из statistics, затем более старый ПЕРВЫЙ
     * полный снимок marketplace» статус отклонялся как устаревший, но снимок
     * принимался — и его статусные оси всё равно записывались. Заказ показывал
     * актуальный CANCELLED рядом с устаревшими supplier_status и wb_status.
     */
    public function testOlderSnapshotDoesNotWriteItsStaleStatusAxes(): void
    {
        $this->normalizeStatistics(new \DateTimeImmutable('-1 hour'), cancelShared: true);
        $this->normalizeMarketplace(new \DateTimeImmutable('-2 hours'));

        $this->em->clear();
        $order = $this->orders->findByExternalId($this->companyId, IngestSource::WILDBERRIES, self::CONNECTION_REF, self::SHARED_RID);
        self::assertNotNull($order);

        $attributes = $order->getAttributes() ?? [];

        // Статус остался от свежей отмены.
        self::assertSame(IngestOrderStatus::CANCELLED, $order->getStatus());
        self::assertTrue($attributes['is_cancel'] ?? null);

        // Устаревшие оси статуса из старого снимка не записались.
        self::assertArrayNotHasKey('wb_status', $attributes);
        self::assertArrayNotHasKey('supplier_status', $attributes);

        // Снимочные атрибуты при этом применились: они не про статус.
        self::assertSame('WB-GI-271305969', $attributes['supply_id'] ?? null);
    }

    /**
     * Гранулярность сравнения отметок — СЕКУНДА, и это зафиксировано тестом,
     * а не подразумевается.
     *
     * Стандартный тип datetime_immutable пишет `Y-m-d H:i:s` независимо от
     * точности колонки, поэтому два наблюдения внутри одной секунды считаются
     * одновременными и побеждает обработанное последним. За пределами секунды
     * порядок соблюдается строго — это и проверяем обеими половинами теста.
     * Перечитывание из БД обязательно: без него сравнение шло бы по объекту в
     * памяти, где микросекунды ещё живы, и тест утверждал бы неправду.
     */
    public function testObservationOrderingIsSecondGranular(): void
    {
        $base = new \DateTimeImmutable('2026-09-01T10:00:00.500000+00:00');
        $this->normalizeMarketplace($base);
        $this->em->clear();

        // Внутри той же секунды — считается одновременным, побеждает поздний.
        $this->applyPrice(111100, $base->modify('-200 microseconds'), 'sub-second');
        self::assertSame('111100', $this->sharedItemPrice());

        // На секунду раньше — уже строго устаревшее наблюдение.
        $this->applyPrice(222200, $base->modify('-2 seconds'), 'older-second');
        self::assertSame('111100', $this->sharedItemPrice());
    }

    private function applyPrice(int $priceMinor, \DateTimeImmutable $fetchedAt, string $key): void
    {
        $rows = $this->marketplaceRows();
        foreach ($rows as $index => $row) {
            if (self::SHARED_RID === ($row['rid'] ?? null)) {
                $rows[$index]['price'] = $priceMinor;
            }
        }

        $this->normalize($this->storeRaw(WbResourceType::ORDERS_MARKETPLACE, 'marketplace-'.$key, $rows, $fetchedAt));
        $this->em->clear();
    }

    private function sharedItemPrice(): ?string
    {
        $order = $this->orders->findByExternalId($this->companyId, IngestSource::WILDBERRIES, self::CONNECTION_REF, self::SHARED_RID);
        self::assertNotNull($order);

        $items = $this->items->findByOrderIndexedByLineKey($this->companyId, $order->getId());

        return $items[array_key_first($items)]->getPriceMinor();
    }

    /**
     * Регрессия: `isCancel = false` считался статусным наблюдением.
     *
     * Крон ставит statistics ПОСЛЕ marketplace, поэтому оно почти всегда
     * новее — и «отмены не было» затирало бы реальный этап жизни заказа.
     * Отсутствие статуса статусом не является.
     */
    public function testNonCancellingStatisticsDoesNotOverwriteLifecycleStatus(): void
    {
        $this->normalizeMarketplace(new \DateTimeImmutable('-2 hours'));

        $order = $this->orders->findByExternalId($this->companyId, IngestSource::WILDBERRIES, self::CONNECTION_REF, self::SHARED_RID);
        self::assertNotNull($order);
        self::assertSame(IngestOrderStatus::SHIPPED, $order->getStatus());

        // Более свежее наблюдение из statistics, но отмены в нём нет.
        $this->normalizeStatistics(new \DateTimeImmutable('-1 hour'));

        $this->em->clear();
        $order = $this->orders->findByExternalId($this->companyId, IngestSource::WILDBERRIES, self::CONNECTION_REF, self::SHARED_RID);
        self::assertNotNull($order);
        self::assertSame(IngestOrderStatus::SHIPPED, $order->getStatus(), 'Отсутствие отмены не является статусом.');

        // И журнал не растёт на пустом наблюдении.
        self::assertSame(1, $this->events->countByOrder($this->companyId, $order->getId()));
    }

    /**
     * Отмена — единственное, что statistics действительно сообщает о статусе,
     * и она обязана применяться.
     */
    public function testCancellingStatisticsStillOverridesLifecycleStatus(): void
    {
        $this->normalizeMarketplace(new \DateTimeImmutable('-2 hours'));
        $this->normalizeStatistics(new \DateTimeImmutable('-1 hour'), cancelShared: true);

        $this->em->clear();
        $order = $this->orders->findByExternalId($this->companyId, IngestSource::WILDBERRIES, self::CONNECTION_REF, self::SHARED_RID);
        self::assertNotNull($order);
        self::assertSame(IngestOrderStatus::CANCELLED, $order->getStatus());
    }

    /**
     * Регрессия: схема заказа, созданного statistics с неизвестным типом
     * склада, оставалась UNKNOWN навсегда. Авторитетного снимка для него может
     * не быть вовсе — заказы FBO в marketplace-поток не попадают.
     */
    public function testLaterStatisticsRefinesUnknownScheme(): void
    {
        $rows = $this->statisticsRows();
        foreach ($rows as $index => $row) {
            if (self::STATISTICS_ONLY_SRID === ($row['srid'] ?? null)) {
                $rows[$index]['warehouseType'] = 'Склад будущего';
            }
        }
        $this->normalize($this->storeRaw(
            WbResourceType::ORDERS_STATISTICS,
            'statistics-unknown-scheme',
            $rows,
            new \DateTimeImmutable('-2 hours'),
        ));

        $order = $this->orders->findByExternalId($this->companyId, IngestSource::WILDBERRIES, self::CONNECTION_REF, self::STATISTICS_ONLY_SRID);
        self::assertNotNull($order);
        self::assertSame(IngestOrderScheme::UNKNOWN, $order->getScheme());

        // Следующий приём того же потока уже знает тип склада.
        $this->normalizeStatistics(new \DateTimeImmutable('-1 hour'), cancelShared: true);

        $this->em->clear();
        $order = $this->orders->findByExternalId($this->companyId, IngestSource::WILDBERRIES, self::CONNECTION_REF, self::STATISTICS_ONLY_SRID);
        self::assertNotNull($order);
        self::assertSame(IngestOrderScheme::FBO, $order->getScheme());
    }

    /**
     * Регрессия: частичное наблюдение без статуса отбрасывалось целиком.
     *
     * Оно привязывалось к статусной оси, а `isCancel = false` статуса не
     * несёт — и все непротиворечивые данные потока (сумма, склад, уточнение
     * схемы) терялись для уже существующего заказа навсегда. У частичного
     * наблюдения своя отметка свежести.
     */
    public function testNonCancellingStatisticsStillUpdatesItsOwnAttributes(): void
    {
        $this->normalizeMarketplace(new \DateTimeImmutable('-2 hours'));
        $this->normalizeStatistics(new \DateTimeImmutable('-1 hour'));

        $this->em->clear();
        $order = $this->orders->findByExternalId($this->companyId, IngestSource::WILDBERRIES, self::CONNECTION_REF, self::SHARED_RID);
        self::assertNotNull($order);

        $attributes = $order->getAttributes() ?? [];
        self::assertSame('190000', $attributes['finished_price_minor'] ?? null);
        self::assertSame('Тестовый склад', $attributes['warehouse_name'] ?? null);

        // Статус при этом не тронут: отсутствие отмены статусом не является.
        self::assertSame(IngestOrderStatus::SHIPPED, $order->getStatus());
    }

    /**
     * Устаревшее частичное наблюдение своих данных не применяет.
     */
    public function testStalePartialObservationDoesNotOverwriteNewerOne(): void
    {
        $this->normalizeStatistics(new \DateTimeImmutable('-1 hour'), cancelShared: true);

        $stale = $this->statisticsRows();
        foreach ($stale as $index => $row) {
            if (self::SHARED_RID === ($row['srid'] ?? null)) {
                $stale[$index]['finishedPrice'] = 1;
            }
        }
        $this->normalize($this->storeRaw(
            WbResourceType::ORDERS_STATISTICS,
            'statistics-stale-price',
            $stale,
            new \DateTimeImmutable('-3 hours'),
        ));

        $this->em->clear();
        $order = $this->orders->findByExternalId($this->companyId, IngestSource::WILDBERRIES, self::CONNECTION_REF, self::SHARED_RID);
        self::assertNotNull($order);
        self::assertSame('190000', ($order->getAttributes() ?? [])['finished_price_minor'] ?? null);
    }

    /**
     * Заказ, заведённый наблюдением без статуса, не должен получать ни
     * выдуманного события журнала, ни статусной отметки: первое настоящее
     * наблюдение обязано приниматься, даже если оно старше.
     */
    public function testOrderCreatedWithoutStatusRecordsNoEventAndStaysOpenToOlderStatus(): void
    {
        $this->normalizeStatistics(new \DateTimeImmutable('-1 hour'));

        $order = $this->orders->findByExternalId($this->companyId, IngestSource::WILDBERRIES, self::CONNECTION_REF, self::SHARED_RID);
        self::assertNotNull($order);
        self::assertSame(0, $this->events->countByOrder($this->companyId, $order->getId()));
        self::assertNull($order->getStatusObservedAt());

        $this->normalizeMarketplace(new \DateTimeImmutable('-3 hours'));

        $this->em->clear();
        $order = $this->orders->findByExternalId($this->companyId, IngestSource::WILDBERRIES, self::CONNECTION_REF, self::SHARED_RID);
        self::assertNotNull($order);
        self::assertSame(IngestOrderStatus::SHIPPED, $order->getStatus());
        self::assertSame(1, $this->events->countByOrder($this->companyId, $order->getId()));
    }

    /**
     * Полный снимок заказа не должен зависеть от порядка прихода потоков.
     */
    public function testCanonicalFieldsDoNotDependOnFeedOrder(): void
    {
        $this->normalizeMarketplace(new \DateTimeImmutable('-3 hours'));
        $this->normalizeStatistics(new \DateTimeImmutable('-2 hours'));
        $direct = $this->snapshot(self::SHARED_RID);

        // Тот же заказ у другой компании, но потоки в обратном порядке.
        $this->companyId = Uuid::uuid7()->toString();
        $this->normalizeStatistics(new \DateTimeImmutable('-3 hours'));
        $this->normalizeMarketplace(new \DateTimeImmutable('-2 hours'));
        $reversed = $this->snapshot(self::SHARED_RID);

        self::assertSame($direct, $reversed);
    }

    /**
     * Повторная нормализация того же сырья не должна плодить ни заказы, ни
     * позиции, ни события журнала.
     */
    public function testRenormalizingTheSameRawIsIdempotent(): void
    {
        $fetchedAt = new \DateTimeImmutable('-2 hours');
        $rawId = $this->storeRaw(
            WbResourceType::ORDERS_MARKETPLACE,
            'marketplace-replay',
            $this->marketplaceRows(),
            $fetchedAt,
        );

        $this->normalize($rawId);
        $this->normalize($rawId);

        $order = $this->orders->findByExternalId($this->companyId, IngestSource::WILDBERRIES, self::CONNECTION_REF, self::SHARED_RID);
        self::assertNotNull($order);

        self::assertSame(1, $this->orderCount(self::SHARED_RID));
        self::assertCount(1, $this->items->findByOrderIndexedByLineKey($this->companyId, $order->getId()));
        self::assertSame(1, $this->events->countByOrder($this->companyId, $order->getId()));
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(string $externalId): array
    {
        $this->em->clear();
        $order = $this->orders->findByExternalId($this->companyId, IngestSource::WILDBERRIES, self::CONNECTION_REF, $externalId);
        self::assertNotNull($order);

        $items = [];
        foreach ($this->items->findByOrderIndexedByLineKey($this->companyId, $order->getId()) as $lineKey => $item) {
            $items[$lineKey] = [
                'priceMinor' => $item->getPriceMinor(),
                'currency' => $item->getCurrency(),
                'externalSku' => $item->getExternalSku(),
                'offerId' => $item->getOfferId(),
            ];
        }
        ksort($items);

        return [
            'scheme' => $order->getScheme()->value,
            'orderedAt' => $order->getOrderedAt()->format(\DATE_ATOM),
            'externalOrderId' => $order->getExternalOrderId(),
            'items' => $items,
        ];
    }

    private function normalizeMarketplace(\DateTimeImmutable $fetchedAt): void
    {
        $rawId = $this->storeRaw(
            WbResourceType::ORDERS_MARKETPLACE,
            'marketplace-'.$fetchedAt->getTimestamp(),
            $this->marketplaceRows(),
            $fetchedAt,
        );

        $this->normalize($rawId);
    }

    private function normalizeStatistics(\DateTimeImmutable $fetchedAt, bool $cancelShared = false): void
    {
        $rows = $this->statisticsRows();
        if ($cancelShared) {
            foreach ($rows as $index => $row) {
                if (self::SHARED_RID === ($row['srid'] ?? null)) {
                    $rows[$index]['isCancel'] = true;
                }
            }
        }

        $rawId = $this->storeRaw(
            WbResourceType::ORDERS_STATISTICS,
            'statistics-'.$fetchedAt->getTimestamp().($cancelShared ? '-cancelled' : ''),
            $rows,
            $fetchedAt,
        );

        $this->normalize($rawId);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function storeRaw(string $resourceType, string $externalId, array $rows, \DateTimeImmutable $fetchedAt): string
    {
        /** @var RawStorageFacade $facade */
        $facade = self::getContainer()->get(RawStorageFacade::class);

        return $facade->storeAndGetIds(new RawBatch(
            companyId: $this->companyId,
            connectionRef: self::CONNECTION_REF,
            shopRef: 'shop-main',
            source: IngestSource::WILDBERRIES,
            resourceType: $resourceType,
            externalId: $externalId,
            syncJobId: Uuid::uuid7()->toString(),
            fetchedAt: $fetchedAt,
            rows: $rows,
        ))[0];
    }

    /**
     * Нормализация ВСЕГДА идёт после сброса EntityManager.
     *
     * В проде сырьё сохраняет один процесс, а разбирает отдельный обработчик
     * Messenger, который читает запись из БД по идентификатору. Тест, зовущий
     * Action сразу после storeRaw(), получал бы управляемый объект из памяти —
     * с исходной зоной и микросекундами — и маскировал бы то, что после
     * реального round-trip отметка меняется.
     */
    private function normalize(string $rawId): void
    {
        $this->em->flush();
        $this->em->clear();

        ($this->action)(new NormalizeRawRecordCommand($rawId, $this->companyId));
    }

    private function orderCount(string $externalId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ingest_orders WHERE company_id = :c AND external_id = :e',
            ['c' => $this->companyId, 'e' => $externalId],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function marketplaceRows(): array
    {
        $base = \dirname(__DIR__, 5).'/Fixtures/Marketplace/Orders/';
        $orders = json_decode((string) file_get_contents($base.'wb_marketplace_orders.json'), true, 512, \JSON_THROW_ON_ERROR)['orders'];

        $statuses = [];
        foreach (json_decode((string) file_get_contents($base.'wb_marketplace_orders_status.json'), true, 512, \JSON_THROW_ON_ERROR)['orders'] as $status) {
            $statuses[$status['id']] = $status;
        }

        // Коннектор подмешивает статусы до записи в raw — воспроизводим это.
        $rows = [];
        foreach ($orders as $row) {
            $rows[] = isset($statuses[$row['id']])
                ? $row + ['_ingestion_status' => $statuses[$row['id']]]
                : $row;
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function statisticsRows(): array
    {
        $path = \dirname(__DIR__, 5).'/Fixtures/Marketplace/Orders/wb_statistics_orders.json';

        return json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
    }
}
