<?php

namespace App\Tests\Unit\Analytics;

use App\Analytics\Application\DrilldownBuilder;
use App\Analytics\Application\DashboardSnapshotService;
use App\Analytics\Application\LastUpdatedAtResolver;
use App\Analytics\Application\Widget\FreeCashWidgetBuilder;
use App\Analytics\Application\Widget\CashflowSplitWidgetBuilder;
use App\Analytics\Application\Widget\InflowWidgetBuilder;
use App\Analytics\Application\Widget\OutflowWidgetBuilder;
use App\Analytics\Application\Widget\ProfitWidgetBuilder;
use App\Analytics\Application\Widget\RevenueWidgetBuilder;
use App\Analytics\Application\Widget\TopCashWidgetBuilder;
use App\Analytics\Application\Widget\TopPnlWidgetBuilder;
use App\Analytics\Application\Widget\ParetoTopItemsBuilder;
use App\Cash\Repository\Accounts\MoneyAccountDailyBalanceRepository;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Accounts\MoneyFundMovementRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Accounts\AccountBalanceProvider;
use App\Analytics\Domain\Period;
use App\Company\Entity\Company;
use App\Finance\Facts\FactsProviderInterface;
use App\Finance\Report\PlReportCalculator;
use App\Finance\Report\PlReportGridBuilder;
use App\Repository\PLCategoryRepository;
use App\Repository\PLDailyTotalRepository;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class DashboardSnapshotServiceTest extends TestCase
{
    public function testUsesCacheForSamePeriodAndCompany(): void
    {
        $cache = new InMemoryCacheSpy();
        $accountRepository = $this->createMock(MoneyAccountRepository::class);
        $accountRepository->method('findByFilters')->willReturn([]);

        $dailyBalanceRepository = $this->createMock(MoneyAccountDailyBalanceRepository::class);
        $accountBalanceProvider = new AccountBalanceProvider($dailyBalanceRepository);

        $fundMovementRepository = $this->createMock(MoneyFundMovementRepository::class);
        $fundMovementRepository->method('sumFundBalancesUpToDate')->willReturn([]);

        $cashTransactionRepository = $this->createMock(CashTransactionRepository::class);
        $cashTransactionRepository->method('sumOutflowExcludeTransfers')->willReturn(0.0);
        $cashTransactionRepository->method('sumOutflowByDayExcludeTransfers')->willReturn([]);
        $cashTransactionRepository->method('sumCapexOutflowExcludeTransfers')->willReturn(0.0);
        $cashTransactionRepository->method('sumOutflowByCategoryExcludeTransfers')->willReturn([]);
        $cashTransactionRepository->method('sumNetByFlowKindExcludeTransfers')->willReturn([
            'OPERATING' => 0.0,
            'INVESTING' => 0.0,
            'FINANCING' => 0.0,
        ]);

        $widgetBuilder = new FreeCashWidgetBuilder($accountRepository, $accountBalanceProvider, $fundMovementRepository, new DrilldownBuilder());
        $inflowWidgetBuilder = new InflowWidgetBuilder($accountRepository, $cashTransactionRepository, new DrilldownBuilder());
        $outflowWidgetBuilder = new OutflowWidgetBuilder($cashTransactionRepository, new DrilldownBuilder());
        $cashflowSplitWidgetBuilder = new CashflowSplitWidgetBuilder($cashTransactionRepository, new DrilldownBuilder());

        $plCategoryRepository = $this->createMock(PLCategoryRepository::class);
        $plCategoryRepository->method('findBy')->willReturn([]);

        $factsProvider = $this->createMock(FactsProviderInterface::class);
        $factsProvider->method('value')->willReturn(0.0);

        $plReportGridBuilder = new PlReportGridBuilder(new PlReportCalculator($plCategoryRepository, $factsProvider));

        $dailyTotalRepository = $this->createMock(PLDailyTotalRepository::class);
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSingleScalarResult'])
            ->getMock();
        $query->method('getSingleScalarResult')->willReturn(0);

        $queryBuilder = $this->getMockBuilder(QueryBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['select', 'andWhere', 'setParameter', 'getQuery'])
            ->getMock();
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $dailyTotalRepository->method('createQueryBuilder')->willReturn($queryBuilder);

        $revenueWidgetBuilder = new RevenueWidgetBuilder($plReportGridBuilder, $plCategoryRepository, $dailyTotalRepository, new DrilldownBuilder());
        $profitWidgetBuilder = new ProfitWidgetBuilder($plReportGridBuilder, $plCategoryRepository, new DrilldownBuilder());
        $topCashWidgetBuilder = new TopCashWidgetBuilder($cashTransactionRepository, new DrilldownBuilder());
        $topPnlWidgetBuilder = new TopPnlWidgetBuilder($dailyTotalRepository, $plCategoryRepository, new ParetoTopItemsBuilder(), new DrilldownBuilder());

        $lastUpdatedAtResolver = new LastUpdatedAtResolver(
            $cashTransactionRepository,
            $fundMovementRepository,
            $dailyTotalRepository,
        );

        $service = new DashboardSnapshotService($cache, $widgetBuilder, $inflowWidgetBuilder, $outflowWidgetBuilder, $cashflowSplitWidgetBuilder, $revenueWidgetBuilder, $profitWidgetBuilder, $topCashWidgetBuilder, $topPnlWidgetBuilder, $lastUpdatedAtResolver);

        $company = $this->createCompany('76f4b0c3-6fd3-41bb-b426-0ea2fd21ae12');
        $period = new Period(new DateTimeImmutable('2026-03-01'), new DateTimeImmutable('2026-03-31'));

        $first = $service->getSnapshot($company, $period)->toArray();
        $second = $service->getSnapshot($company, $period)->toArray();

        self::assertSame(1, $cache->missesCount);
        self::assertEquals($first, $second);
        self::assertSame('exclude', $first['context']['vat_mode']);
        self::assertNull($first['context']['last_updated_at']);
        self::assertSame(0.0, $first['widgets']['free_cash']['value']);
        self::assertSame(0.0, $first['widgets']['free_cash']['cash_at_end']);
        self::assertSame(0.0, $first['widgets']['inflow']['sum']);
        self::assertSame(0.0, $first['widgets']['inflow']['avg_daily']);
        self::assertCount(0, $first['widgets']['inflow']['series']);
        self::assertArrayHasKey('operating', $first['widgets']['cashflow_split']);
        self::assertArrayHasKey('investing', $first['widgets']['cashflow_split']);
        self::assertArrayHasKey('financing', $first['widgets']['cashflow_split']);
        self::assertArrayHasKey('total', $first['widgets']['cashflow_split']);
        self::assertSame(0.0, $first['widgets']['cashflow_split']['operating']['net']);
        self::assertIsArray($first['widgets']['alerts']['items']);
        self::assertIsArray($first['widgets']['alerts']['warnings']);
        self::assertIsArray($first['widgets']['top_cash']);
        self::assertArrayHasKey('items', $first['widgets']['top_cash']);
        self::assertArrayHasKey('other', $first['widgets']['top_cash']);
        self::assertContains('PL_REGISTRY_EMPTY', $first['widgets']['alerts']['warnings']);
        self::assertIsArray($first['widgets']['top_pnl']);
        self::assertArrayHasKey('coverage_target', $first['widgets']['top_pnl']);
        self::assertArrayHasKey('max_items', $first['widgets']['top_pnl']);
        self::assertArrayHasKey('items', $first['widgets']['top_pnl']);
        self::assertArrayHasKey('other', $first['widgets']['top_pnl']);
    }

    private function createCompany(string $companyId): Company
    {
        $company = $this->getMockBuilder(Company::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();

        $company->method('getId')->willReturn($companyId);

        return $company;
    }
}

final class InMemoryCacheSpy implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $data = [];

    public int $missesCount = 0;

    public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
    {
        if (!array_key_exists($key, $this->data)) {
            ++$this->missesCount;
            $this->data[$key] = $callback(new StubCacheItem($key));
        }

        return $this->data[$key];
    }

    public function delete(string $key): bool
    {
        unset($this->data[$key]);

        return true;
    }
}

final class StubCacheItem implements ItemInterface
{
    private mixed $value = null;

    public function __construct(private readonly string $key)
    {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->value;
    }

    public function isHit(): bool
    {
        return false;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function expiresAt(?DateTimeInterface $expiration): static
    {
        return $this;
    }

    public function expiresAfter(DateInterval|int|null $time): static
    {
        return $this;
    }

    public function tag(string|iterable $tags): static
    {
        return $this;
    }

    public function getMetadata(): array
    {
        return [];
    }
}
