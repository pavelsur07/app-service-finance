<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Marketplace\Application\Command\SyncConnectionCommand;
use App\Marketplace\Application\Service\WbFinancialReportSyncPlannerInterface;
use App\Marketplace\Application\Service\WbFinancialReportSyncPlanResult;
use App\Marketplace\Application\SyncConnectionAction;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\FinancialReportSyncMode;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Marketplace\Service\Integration\MarketplaceAdapterRegistry;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class SyncConnectionActionTest extends TestCase
{
    public function testWildberriesManualSyncSchedulesPlannerTasksInsteadOfFetchingRawReport(): void
    {
        $companyId = '11111111-1111-4111-8111-111111111111';
        $connectionId = '22222222-2222-4222-8222-222222222222';
        $company = new Company($companyId, self::uninitialized(User::class));
        $connection = new MarketplaceConnection($connectionId, $company, MarketplaceType::WILDBERRIES);

        $repository = $this->createMock(MarketplaceConnectionRepository::class);
        $repository->expects(self::once())
            ->method('find')
            ->with($connectionId)
            ->willReturn($connection);

        $adapterRegistry = $this->createMock(MarketplaceAdapterRegistry::class);
        $adapterRegistry->expects(self::never())->method('get');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $planner = $this->createMock(WbFinancialReportSyncPlannerInterface::class);
        $planner->expects(self::once())
            ->method('planRangeLimited')
            ->with(
                self::callback(
                    static fn (\DateTimeImmutable $date): bool => '2026-02-10 00:00:00 Europe/Moscow'
                        === $date->format('Y-m-d H:i:s e'),
                ),
                self::callback(
                    static fn (\DateTimeImmutable $date): bool => '2026-02-10 00:00:00 Europe/Moscow'
                        === $date->format('Y-m-d H:i:s e'),
                ),
                FinancialReportSyncMode::MANUAL,
                31,
                $companyId,
                $connectionId,
                true,
            )
            ->willReturn(new WbFinancialReportSyncPlanResult(
                candidatesCount: 1,
                dispatchLimit: 31,
                attemptedCount: 1,
                dispatchedCount: 1,
                skippedByLimitCount: 0,
            ));

        $action = new SyncConnectionAction($repository, $adapterRegistry, $entityManager, $planner);

        $scheduledCount = $action(new SyncConnectionCommand(
            companyId: $companyId,
            connectionId: $connectionId,
            fromDate: new \DateTimeImmutable('2026-02-10 12:30:00 UTC'),
            toDate: new \DateTimeImmutable('2026-02-10 23:59:59 UTC'),
        ));

        self::assertSame(1, $scheduledCount);
        self::assertNull($connection->getLastSuccessfulSyncAt());
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $className
     *
     * @return T
     */
    private static function uninitialized(string $className): object
    {
        return (new \ReflectionClass($className))->newInstanceWithoutConstructor();
    }
}
