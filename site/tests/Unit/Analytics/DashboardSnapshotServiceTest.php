<?php

namespace App\Tests\Unit\Analytics;

use App\Analytics\Application\DashboardSnapshotService;
use App\Analytics\Application\Widget\FreeCashWidgetBuilder;
use App\Analytics\Application\Widget\InflowWidgetBuilder;
use App\Cash\Repository\Accounts\MoneyAccountDailyBalanceRepository;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Accounts\MoneyFundMovementRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Accounts\AccountBalanceProvider;
use App\Analytics\Domain\Period;
use App\Company\Entity\Company;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
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

        $widgetBuilder = new FreeCashWidgetBuilder($accountRepository, $accountBalanceProvider, $fundMovementRepository);
        $inflowWidgetBuilder = new InflowWidgetBuilder($accountRepository, $cashTransactionRepository);

        $service = new DashboardSnapshotService($cache, $widgetBuilder, $inflowWidgetBuilder);

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
