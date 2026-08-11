<?php

namespace App\Tests\Unit\Analytics;

use App\Analytics\Application\DashboardSnapshotService;
use App\Analytics\Application\DrilldownBuilder;
use App\Analytics\Application\LastUpdatedAtResolver;
use App\Analytics\Application\Widget\CashflowSplitWidgetBuilder;
use App\Analytics\Application\Widget\FreeCashWidgetBuilder;
use App\Analytics\Application\Widget\InflowWidgetBuilder;
use App\Analytics\Application\Widget\OutflowWidgetBuilder;
use App\Analytics\Application\Widget\ParetoTopItemsBuilder;
use App\Analytics\Application\Widget\ProfitWidgetBuilder;
use App\Analytics\Application\Widget\RevenueWidgetBuilder;
use App\Analytics\Application\Widget\TopCashWidgetBuilder;
use App\Analytics\Application\Widget\TopPnlWidgetBuilder;
use App\Analytics\Domain\Period;
use App\Analytics\Infrastructure\Cache\SnapshotCacheInvalidator;
use App\Cash\Enum\FiatCurrency;
use App\Cash\Repository\Accounts\MoneyAccountDailyBalanceRepository;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Accounts\MoneyFundMovementRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Accounts\AccountBalanceProvider;
use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
use App\Company\Entity\User;
use App\Company\Security\AccessLevel;
use App\Company\Security\Module;
use App\Company\Security\ModuleAccessResolver;
use App\Company\Security\SystemCompanyRoles;
use App\Finance\Facts\FactsProviderInterface;
use App\Finance\Report\PlReportCalculator;
use App\Finance\Report\PlReportGridBuilder;
use App\Finance\Repository\PLCategoryRepository;
use App\Finance\Repository\PLDailyTotalRepository;
use App\Shared\Service\ActiveCompanyService;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class DashboardSnapshotServiceTest extends TestCase
{
    public function testUsesCacheForSamePeriodAndCompany(): void
    {
        $cache = new InMemoryCacheSpy();
        $company = $this->createCompany('76f4b0c3-6fd3-41bb-b426-0ea2fd21ae12');
        $service = $this->buildService($cache, true, (string) $company->getId());
        $period = new Period(new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-03-31'));

        $first = $service->getSnapshot($company, $period)->toArray();
        $second = $service->getSnapshot($company, $period)->toArray();

        self::assertSame(1, $cache->snapshotMissesCount);
        self::assertEquals($first, $second);
        self::assertSame('exclude', $first['context']['vat_mode']);
        self::assertSame('RUB', $first['context']['cash_currency']);
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
        self::assertIsArray($first['widgets']['top_cash']);
        self::assertArrayHasKey('items', $first['widgets']['top_cash']);
        self::assertArrayHasKey('other', $first['widgets']['top_cash']);
        self::assertIsArray($first['widgets']['warnings']['items']);
        self::assertContains('PL_REGISTRY_EMPTY', array_column($first['widgets']['warnings']['items'], 'code'));
        self::assertIsArray($first['widgets']['top_pnl']);
        self::assertArrayHasKey('coverage_target', $first['widgets']['top_pnl']);
        self::assertArrayHasKey('max_items', $first['widgets']['top_pnl']);
        self::assertArrayHasKey('items', $first['widgets']['top_pnl']);
        self::assertArrayHasKey('other', $first['widgets']['top_pnl']);

        // Другая валюта ДДС — отдельный ключ кэша, а не переиспользование рублёвого снапшота.
        $usd = $service->getSnapshot($company, $period, FiatCurrency::USD)->toArray();

        self::assertSame(2, $cache->snapshotMissesCount);
        self::assertSame('USD', $usd['context']['cash_currency']);

        (new SnapshotCacheInvalidator($cache))->invalidateForCompany($company);
        $service->getSnapshot($company, $period);

        self::assertSame(3, $cache->snapshotMissesCount);
    }

    public function testNoFinanceReadReturnsContextOnly(): void
    {
        $cache = new InMemoryCacheSpy();
        $company = $this->createCompany('76f4b0c3-6fd3-41bb-b426-0ea2fd21ae12');
        $service = $this->buildService($cache, false, (string) $company->getId());
        $period = new Period(new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-03-31'));

        $payload = $service->getSnapshot($company, $period)->toArray();

        self::assertArrayHasKey('context', $payload);
        self::assertArrayHasKey('widgets', $payload);
        self::assertArrayNotHasKey('free_cash', $payload['widgets']);
        self::assertArrayNotHasKey('revenue', $payload['widgets']);
        self::assertArrayHasKey('alerts', $payload['widgets']);
        self::assertArrayHasKey('warnings', $payload['widgets']);
        self::assertSame(1, $cache->snapshotMissesCount);
    }

    public function testSystemContextBuildsFullSnapshotRegardlessOfUserPermissions(): void
    {
        $cache = new InMemoryCacheSpy();
        $company = $this->createCompany('76f4b0c3-6fd3-41bb-b426-0ea2fd21ae12');
        $service = $this->buildService($cache, false, (string) $company->getId());
        $period = new Period(new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-03-31'));

        $payload = $service->getSnapshot($company, $period, forSystemContext: true)->toArray();

        self::assertArrayHasKey('context', $payload);
        self::assertArrayHasKey('widgets', $payload);
        self::assertArrayHasKey('free_cash', $payload['widgets']);
        self::assertArrayHasKey('revenue', $payload['widgets']);
        self::assertArrayHasKey('inflow', $payload['widgets']);
        self::assertArrayHasKey('outflow', $payload['widgets']);
        self::assertArrayHasKey('cashflow_split', $payload['widgets']);
        self::assertArrayHasKey('profit', $payload['widgets']);
        self::assertArrayHasKey('top_cash', $payload['widgets']);
        self::assertArrayHasKey('top_pnl', $payload['widgets']);
        self::assertSame(1, $cache->snapshotMissesCount);
    }

    private function buildService(CacheInterface $cache, bool $allowFinanceRead, string $companyId): DashboardSnapshotService
    {
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

        $snapshotCacheInvalidator = new SnapshotCacheInvalidator($cache);
        $moduleAccessResolver = $this->createResolver($companyId, $allowFinanceRead);

        return new DashboardSnapshotService(
            $cache,
            $widgetBuilder,
            $inflowWidgetBuilder,
            $outflowWidgetBuilder,
            $cashflowSplitWidgetBuilder,
            $revenueWidgetBuilder,
            $profitWidgetBuilder,
            $topCashWidgetBuilder,
            $topPnlWidgetBuilder,
            $snapshotCacheInvalidator,
            $lastUpdatedAtResolver,
            $moduleAccessResolver,
            new NullLogger(),
        );
    }

    private function createCompany(string $companyId): Company
    {
        $owner = UserBuilder::aUser()->withId('11111111-1111-1111-1111-111111111111')->build();

        return CompanyBuilder::aCompany()->withId($companyId)->withOwner($owner)->build();
    }

    private function createResolver(string $companyId, bool $allowFinanceRead): ModuleAccessResolver
    {
        $owner = UserBuilder::aUser()->withId('11111111-1111-1111-1111-111111111111')->build();
        $user = UserBuilder::aUser()->withIndex(2)->withId('22222222-2222-2222-2222-222222222222')->build();
        $company = CompanyBuilder::aCompany()->withId($companyId)->withOwner($owner)->build();

        if ($allowFinanceRead) {
            $role = new CompanyRole(
                SystemCompanyRoles::FULL_ACCESS_ID,
                'Полный доступ',
                [Module::FINANCE->value => AccessLevel::WRITE->value],
            );
        } else {
            $role = new CompanyRole(
                SystemCompanyRoles::MARKETPLACE_ID,
                'Менеджер маркетплейсов',
                [Module::MARKETPLACE->value => AccessLevel::WRITE->value],
            );
        }

        $member = CompanyMemberBuilder::aMember()
            ->withCompany($company)
            ->withUser($user)
            ->withAccessRole($role)
            ->build();

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $activeCompanyService = $this->createMock(ActiveCompanyService::class);
        $activeCompanyService->method('getActiveCompany')->willReturn($company);
        $activeCompanyService->method('getActiveMembership')->willReturn($member);

        return new ModuleAccessResolver($security, $activeCompanyService, new NullLogger());
    }
}

final class InMemoryCacheSpy implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $data = [];

    public int $missesCount = 0;
    public int $snapshotMissesCount = 0;

    public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
    {
        if (!array_key_exists($key, $this->data)) {
            ++$this->missesCount;
            if (str_starts_with($key, 'dashboard_v1_snapshot_')) {
                ++$this->snapshotMissesCount;
            }
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

    public function expiresAt(?\DateTimeInterface $expiration): static
    {
        return $this;
    }

    public function expiresAfter(\DateInterval|int|null $time): static
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
