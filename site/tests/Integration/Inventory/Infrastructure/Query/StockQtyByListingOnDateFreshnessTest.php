<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Infrastructure\Query;

use App\Inventory\Domain\StockSnapshotFreshnessPolicy;
use App\Inventory\Enum\StockStatus;
use App\Inventory\Infrastructure\Query\StockQtyByListingOnDateQuery;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Inventory\StockSnapshotBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Connection;

/**
 * Регрессия: протухший снапшот не должен подаваться как остаток на дату отчёта.
 *
 * Воспроизводит реальный случай PROD — компания с последним снапшотом 106-дневной
 * давности и без активного подключения продолжала отдавать эти остатки как текущие.
 *
 * Дефект доказывается прямо в тесте: тот же набор данных прогоняется дважды —
 * с рабочим порогом и с заведомо огромным (поведение до изменения). Во втором
 * случае протухшая позиция возвращается, то есть тест ловит именно порог свежести,
 * а не проходит вхолостую.
 */
final class StockQtyByListingOnDateFreshnessTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000701';
    private const STALE_LISTING_ID = '55555555-5555-5555-5555-000000000701';
    private const FRESH_LISTING_ID = '55555555-5555-5555-5555-000000000702';
    private const STALE_SESSION_ID = '22222222-2222-2222-2222-000000000701';
    private const FRESH_SESSION_ID = '22222222-2222-2222-2222-000000000702';
    private const FRESH_OZON_SESSION_ID = '22222222-2222-2222-2222-000000000703';
    private const FRESH_OZON_LISTING_ID = '55555555-5555-5555-5555-000000000703';

    // Даты считаются от сегодняшнего дня: свежесть оценивается относительно
    // min(дата отчёта, сегодня), поэтому фиксированные даты сделали бы тест
    // «протухающим» — он начал бы падать через двое суток после написания.
    private string $reportDate;
    private string $staleSnapshotDate;
    private string $freshSnapshotDate;

    protected Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = self::getContainer()->get(Connection::class);

        $today = new \DateTimeImmutable('today');
        $this->reportDate = $today->format('Y-m-d');
        $this->freshSnapshotDate = $today->format('Y-m-d');
        // 106 дней — реальный разрыв компании 5d26f9aa на PROD.
        $this->staleSnapshotDate = $today->modify('-106 days')->format('Y-m-d');

        $this->persistSnapshot(
            MarketplaceType::OZON,
            self::STALE_SESSION_ID,
            $this->staleSnapshotDate,
            self::STALE_LISTING_ID,
            '17.000',
        );
        $this->persistSnapshot(
            MarketplaceType::WILDBERRIES,
            self::FRESH_SESSION_ID,
            $this->freshSnapshotDate,
            self::FRESH_LISTING_ID,
            '4.000',
        );

        $this->em->flush();
    }

    public function testStaleSnapshotIsNotServedAsCurrentStock(): void
    {
        $result = $this->queryWithPolicy(new StockSnapshotFreshnessPolicy())
            ->execute(self::COMPANY_ID, new \DateTimeImmutable($this->reportDate));

        self::assertArrayNotHasKey(
            self::STALE_LISTING_ID,
            $result->qtyByListingId,
            'Снапшот 106-дневной давности не должен участвовать в остатках на дату отчёта.',
        );
        self::assertSame(
            [MarketplaceType::OZON->value => $this->staleSnapshotDate],
            $result->staleSources,
            'Отброшенный источник обязан быть виден вызывающему коду, иначе «нет данных» неотличимо от «протухли».',
        );
    }

    public function testFreshSnapshotOfAnotherSourceIsUnaffected(): void
    {
        $result = $this->queryWithPolicy(new StockSnapshotFreshnessPolicy())
            ->execute(self::COMPANY_ID, new \DateTimeImmutable($this->reportDate));

        self::assertSame([self::FRESH_LISTING_ID => 4.0], $result->qtyByListingId);
        self::assertSame(
            [MarketplaceType::WILDBERRIES->value => $this->freshSnapshotDate],
            $result->snapshotDateBySource,
        );
    }

    public function testWithoutTheFreshnessBoundTheStaleRowIsReturned(): void
    {
        // Поведение до изменения: порог, заведомо перекрывающий возраст снапшота.
        $result = $this->queryWithPolicy(new StockSnapshotFreshnessPolicy(100_000))
            ->execute(self::COMPANY_ID, new \DateTimeImmutable($this->reportDate));

        self::assertSame(
            [self::STALE_LISTING_ID => 17.0, self::FRESH_LISTING_ID => 4.0],
            $result->qtyByListingId,
            'Без ограничения свежести протухшая позиция возвращается — именно это и чинит изменение.',
        );
        self::assertSame([], $result->staleSources);
    }

    public function testMarketplaceFilterNarrowsResultToOneSource(): void
    {
        // Остатки хранятся по всем источникам компании. Отчёт, отфильтрованный по
        // одному маркетплейсу, не должен получать листинги другого.
        $result = $this->queryWithPolicy(new StockSnapshotFreshnessPolicy())->execute(
            self::COMPANY_ID,
            new \DateTimeImmutable($this->reportDate),
            MarketplaceType::WILDBERRIES->value,
        );

        self::assertSame([self::FRESH_LISTING_ID => 4.0], $result->qtyByListingId);
        self::assertSame(
            [MarketplaceType::WILDBERRIES->value => $this->freshSnapshotDate],
            $result->snapshotDateBySource,
        );
        self::assertSame([], $result->staleSources, 'Отфильтрованный источник не должен попадать в отчёт вовсе.');
    }

    public function testWithoutMarketplaceFilterFreshSourcesAreCombined(): void
    {
        // Ветка $marketplace = null: у компании два свежих источника, и оба обязаны
        // попасть в результат. Свежая сессия Ozon перекрывает протухшую из setUp.
        $this->persistSnapshot(
            MarketplaceType::OZON,
            self::FRESH_OZON_SESSION_ID,
            $this->freshSnapshotDate,
            self::FRESH_OZON_LISTING_ID,
            '9.000',
        );
        $this->em->flush();

        $result = $this->queryWithPolicy(new StockSnapshotFreshnessPolicy())
            ->execute(self::COMPANY_ID, new \DateTimeImmutable($this->reportDate), null);

        self::assertSame(
            [
                self::FRESH_OZON_LISTING_ID => 9.0,
                self::FRESH_LISTING_ID => 4.0,
            ],
            $result->qtyByListingId,
        );
        self::assertSame(
            [
                MarketplaceType::OZON->value => $this->freshSnapshotDate,
                MarketplaceType::WILDBERRIES->value => $this->freshSnapshotDate,
            ],
            $result->snapshotDateBySource,
        );
        self::assertSame([], $result->staleSources);
    }

    public function testPeriodEndingInTheFutureStillReturnsTodaysStock(): void
    {
        // Регрессия PROD: пресет «текущий месяц» отдаёт последний день месяца, то есть
        // дату в будущем. Свежий снапшот считался просроченным относительно неё, и
        // отчёт терял все остатки вместе с капитализацией.
        $futureReportDate = (new \DateTimeImmutable('today'))->modify('+24 days');

        $result = $this->queryWithPolicy(new StockSnapshotFreshnessPolicy())
            ->execute(self::COMPANY_ID, $futureReportDate);

        self::assertSame(
            [self::FRESH_LISTING_ID => 4.0],
            $result->qtyByListingId,
            'Сегодняшний остаток обязан попасть в отчёт, даже если период кончается в будущем.',
        );
        self::assertSame(
            [MarketplaceType::WILDBERRIES->value => $this->freshSnapshotDate],
            $result->snapshotDateBySource,
        );
        self::assertSame(
            [MarketplaceType::OZON->value => $this->staleSnapshotDate],
            $result->staleSources,
            'Мёртвая загрузка обязана остаться отброшенной и при периоде в будущем.',
        );
    }

    private function queryWithPolicy(StockSnapshotFreshnessPolicy $policy): StockQtyByListingOnDateQuery
    {
        return new StockQtyByListingOnDateQuery($this->connection, $policy);
    }

    private function persistSnapshot(
        MarketplaceType $source,
        string $sessionId,
        string $snapshotDate,
        string $listingId,
        string $quantity,
    ): void {
        $this->em->persist(
            StockSnapshotBuilder::aStockSnapshot()
                ->withCompanyId(self::COMPANY_ID)
                ->withSnapshotSessionId($sessionId)
                ->withSnapshotDate(new \DateTimeImmutable($snapshotDate))
                ->withSnapshotAt(new \DateTimeImmutable($snapshotDate.'T04:05:00+00:00'))
                ->withSource($source)
                ->withListingId($listingId)
                ->withProductId(null)
                ->withStatus(StockStatus::Available)
                ->withQuantity($quantity)
                ->build(),
        );
    }
}
