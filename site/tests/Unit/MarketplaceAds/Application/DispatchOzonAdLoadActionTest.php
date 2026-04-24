<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds\Application;

use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAds\Application\DispatchOzonAdLoadAction;
use App\MarketplaceAds\Application\Service\AdBatchPlanner;
use App\MarketplaceAds\Entity\AdLoadJob;
use App\MarketplaceAds\Enum\AdLoadJobStatus;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit-тесты {@see DispatchOzonAdLoadAction} после переключения с
 * Messenger-диспатча на синхронный вызов {@see AdBatchPlanner} (Task-11.9a).
 */
final class DispatchOzonAdLoadActionTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000001';

    private MarketplaceFacade $marketplaceFacade;
    private AdLoadJobRepository $adLoadJobRepository;
    private EntityManagerInterface $entityManager;
    private AdBatchPlanner $adBatchPlanner;
    private DispatchOzonAdLoadAction $action;

    protected function setUp(): void
    {
        $this->marketplaceFacade = $this->createMock(MarketplaceFacade::class);
        $this->adLoadJobRepository = $this->createMock(AdLoadJobRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->adBatchPlanner = $this->createMock(AdBatchPlanner::class);

        $this->action = new DispatchOzonAdLoadAction(
            $this->marketplaceFacade,
            $this->adLoadJobRepository,
            $this->entityManager,
            $this->adBatchPlanner,
        );
    }

    public function testHappyPathInvokesPlannerAndMarksJobRunning(): void
    {
        $this->marketplaceFacade
            ->method('getConnectionCredentials')
            ->with(self::COMPANY_ID, MarketplaceType::OZON, MarketplaceConnectionType::PERFORMANCE)
            ->willReturn(['api_key' => 'secret', 'client_id' => 'client-abc']);

        $this->adLoadJobRepository
            ->method('findLatestActiveJobByCompanyAndMarketplace')
            ->willReturn(null);

        $this->adLoadJobRepository->expects(self::once())->method('save');

        // Первый flush — после persist'а job'а (чтобы FK `fk_asb_job` был валиден
        // для batch'ей, которые Planner создаст); второй flush — после markRunning.
        $this->entityManager->expects(self::exactly(2))->method('flush');

        $this->adBatchPlanner->expects(self::once())
            ->method('planBatchesForJob')
            ->with(
                self::isType('string'),
                self::COMPANY_ID,
                self::isInstanceOf(\DateTimeImmutable::class),
                self::isInstanceOf(\DateTimeImmutable::class),
            )
            ->willReturn(3);

        $dateFrom = new \DateTimeImmutable('2026-03-01');
        $dateTo = new \DateTimeImmutable('2026-03-10');

        $job = ($this->action)(self::COMPANY_ID, $dateFrom, $dateTo);

        self::assertInstanceOf(AdLoadJob::class, $job);
        self::assertSame(self::COMPANY_ID, $job->getCompanyId());
        self::assertSame(MarketplaceType::OZON, $job->getMarketplace());
        self::assertSame(AdLoadJobStatus::RUNNING, $job->getStatus(), 'Finalizer ищет RUNNING job\'ы');
    }

    public function testThrowsWhenConnectionNotConfigured(): void
    {
        $this->marketplaceFacade
            ->method('getConnectionCredentials')
            ->willReturn(null);

        $this->adBatchPlanner->expects(self::never())->method('planBatchesForJob');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Ozon Performance connection not configured');

        ($this->action)(
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-10'),
        );
    }

    public function testThrowsWhenDateFromAfterDateTo(): void
    {
        $this->marketplaceFacade
            ->method('getConnectionCredentials')
            ->willReturn(['api_key' => 'key', 'client_id' => null]);

        $this->adBatchPlanner->expects(self::never())->method('planBatchesForJob');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Дата начала не может быть позже даты окончания');

        ($this->action)(
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-03-31'),
            new \DateTimeImmutable('2026-03-01'),
        );
    }

    public function testThrowsWhenDateToIsInFuture(): void
    {
        $this->marketplaceFacade
            ->method('getConnectionCredentials')
            ->willReturn(['api_key' => 'key', 'client_id' => null]);

        $this->adBatchPlanner->expects(self::never())->method('planBatchesForJob');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Нельзя загружать данные за будущие даты');

        ($this->action)(
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('+7 days'),
        );
    }

    public function testThrowsWhenPeriodExceeds62Days(): void
    {
        $this->marketplaceFacade
            ->method('getConnectionCredentials')
            ->willReturn(['api_key' => 'key', 'client_id' => null]);

        $this->adBatchPlanner->expects(self::never())->method('planBatchesForJob');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/превышает лимит Ozon.*62 дней/');

        // 63 дня включительно (дата окончания заведомо в прошлом, чтобы не споткнуться
        // о «future»-guard).
        ($this->action)(
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-03-04'),
        );
    }

    public function testThrowsWhenActiveJobAlreadyExists(): void
    {
        $this->marketplaceFacade
            ->method('getConnectionCredentials')
            ->willReturn(['api_key' => 'key', 'client_id' => null]);

        $existingJob = new AdLoadJob(
            self::COMPANY_ID,
            MarketplaceType::OZON,
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
        );

        $this->adLoadJobRepository
            ->method('findLatestActiveJobByCompanyAndMarketplace')
            ->willReturn($existingJob);

        $this->adBatchPlanner->expects(self::never())->method('planBatchesForJob');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Load already in progress');

        ($this->action)(
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-10'),
        );
    }

    public function testPlannerFailureMarksJobFailedAndRethrows(): void
    {
        $this->marketplaceFacade
            ->method('getConnectionCredentials')
            ->willReturn(['api_key' => 'secret', 'client_id' => 'client-abc']);

        $this->adLoadJobRepository
            ->method('findLatestActiveJobByCompanyAndMarketplace')
            ->willReturn(null);

        $this->adLoadJobRepository->expects(self::once())->method('save');

        $this->adBatchPlanner->method('planBatchesForJob')
            ->willThrowException(new \RuntimeException('No SKU campaigns found for company abc'));

        // Два flush: persist job'а + markFailed. markRunning до этого момента
        // НЕ вызывается (exception обрывает happy-path).
        $this->entityManager->expects(self::exactly(2))->method('flush');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to plan ad load job/');

        ($this->action)(
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-10'),
        );
    }
}
