<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\MessageHandler;

use App\Marketplace\Application\Command\RebuildPreliminaryForPeriodCommand;
use App\Marketplace\Application\RebuildPreliminaryForPeriodAction;
use App\Marketplace\Application\RecalculateSalesDocumentsCostPriceAction;
use App\Marketplace\DTO\RecalculateSalesCostPriceCommand;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceMonthClose;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Message\RecalculateListingCostPriceMessage;
use App\Marketplace\MessageHandler\RecalculateListingCostPriceHandler;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Marketplace\Repository\MarketplaceMonthCloseRepository;
use App\MarketplaceAnalytics\Facade\MarketplaceAnalyticsFacade;
use DG\BypassFinals;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

BypassFinals::allowPaths([
    '*/src/Marketplace/Application/RecalculateSalesDocumentsCostPriceAction.php',
    '*/src/MarketplaceAnalytics/Facade/MarketplaceAnalyticsFacade.php',
]);

final class RecalculateListingCostPriceHandlerTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const LISTING_ID = '22222222-2222-4222-8222-222222222222';
    private const ACTOR_ID = '33333333-3333-4333-8333-333333333333';

    public function testRunsDocumentSnapshotsAndSalesReturnsRebuildInOrder(): void
    {
        $listing = $this->createMock(MarketplaceListing::class);
        $listing->method('getId')->willReturn(self::LISTING_ID);

        $listingRepository = $this->createMock(MarketplaceListingRepository::class);
        $listingRepository->expects(self::once())
            ->method('findAllByCompanyMarketplaceAndIds')
            ->with(self::COMPANY_ID, MarketplaceType::OZON, [self::LISTING_ID])
            ->willReturn([$listing]);

        $monthClose = new MarketplaceMonthClose(
            '44444444-4444-4444-8444-444444444444',
            self::COMPANY_ID,
            MarketplaceType::OZON,
            2026,
            8,
        );
        $monthClose->closeStage(CloseStage::SALES_RETURNS, self::ACTOR_ID, ['old-document'], []);
        $monthClose->setSettings([
            'last_close_was_preliminary' => [CloseStage::SALES_RETURNS->value => true],
        ]);
        $rebuiltMonthClose = new MarketplaceMonthClose(
            '55555555-5555-4555-8555-555555555555',
            self::COMPANY_ID,
            MarketplaceType::OZON,
            2026,
            8,
        );
        $rebuiltMonthClose->closeStage(CloseStage::SALES_RETURNS, self::ACTOR_ID, ['new-document'], []);
        $rebuiltMonthClose->setSettings([
            'last_close_was_preliminary' => [CloseStage::SALES_RETURNS->value => true],
        ]);
        $monthCloseRepository = $this->createMock(MarketplaceMonthCloseRepository::class);
        $monthCloseRepository->expects(self::exactly(2))
            ->method('findByPeriod')
            ->willReturnOnConsecutiveCalls($monthClose, $rebuiltMonthClose);

        $recalculateAction = $this->createMock(RecalculateSalesDocumentsCostPriceAction::class);
        $recalculateAction->expects(self::once())
            ->method('__invoke')
            ->with(self::callback(static fn (RecalculateSalesCostPriceCommand $command): bool => self::COMPANY_ID === $command->companyId
                && MarketplaceType::OZON === $command->marketplace
                && ['22222222-2222-4222-8222-222222222222'] === $command->listingIds
                && '2026-08-01' === $command->dateFrom->format('Y-m-d')
                && '2026-08-02' === $command->dateTo->format('Y-m-d')
            ))
            ->willReturn(['sales' => 2, 'returns' => 1]);

        $analyticsFacade = $this->createMock(MarketplaceAnalyticsFacade::class);
        $analyticsFacade->expects(self::once())
            ->method('recalculateSnapshotsForListings')
            ->with(
                self::COMPANY_ID,
                [self::LISTING_ID],
                self::callback(static fn (\DateTimeImmutable $date): bool => '2026-08-01' === $date->format('Y-m-d')),
                self::callback(static fn (\DateTimeImmutable $date): bool => '2026-08-02' === $date->format('Y-m-d')),
                MarketplaceType::OZON->value,
            );

        $rebuildAction = $this->createMock(RebuildPreliminaryForPeriodAction::class);
        $rebuildAction->expects(self::once())
            ->method('__invoke')
            ->with(self::callback(static fn (RebuildPreliminaryForPeriodCommand $command): bool => 2026 === $command->year
                && 8 === $command->month
                && [CloseStage::SALES_RETURNS->value] === $command->stages
            ));

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects(self::once())->method('acquire')->willReturn(true);
        $lock->expects(self::exactly(2))->method('refresh');
        $lock->expects(self::once())->method('release');
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLock')->willReturn($lock);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(
                '[InventoryCostRecalculation] Completed',
                self::callback(static fn (array $context): bool => true === $context['preliminary_rebuilt']),
            );

        $handler = new RecalculateListingCostPriceHandler(
            $listingRepository,
            $monthCloseRepository,
            $recalculateAction,
            $analyticsFacade,
            $rebuildAction,
            $lockFactory,
            $logger,
        );

        $handler(new RecalculateListingCostPriceMessage(
            companyId: self::COMPANY_ID,
            marketplace: MarketplaceType::OZON->value,
            listingIds: [self::LISTING_ID, self::LISTING_ID],
            dateFrom: '2026-08-01',
            dateTo: '2026-08-02',
            actorUserId: self::ACTOR_ID,
        ));
    }

    public function testDoesNotChangeFinalClosedPeriod(): void
    {
        $listing = $this->createMock(MarketplaceListing::class);
        $listing->method('getId')->willReturn(self::LISTING_ID);
        $listingRepository = $this->createMock(MarketplaceListingRepository::class);
        $listingRepository->method('findAllByCompanyMarketplaceAndIds')->willReturn([$listing]);

        $monthClose = new MarketplaceMonthClose(
            '44444444-4444-4444-8444-444444444444',
            self::COMPANY_ID,
            MarketplaceType::OZON,
            2026,
            8,
        );
        $monthClose->closeStage(CloseStage::SALES_RETURNS, self::ACTOR_ID, [], []);
        $monthCloseRepository = $this->createMock(MarketplaceMonthCloseRepository::class);
        $monthCloseRepository->method('findByPeriod')->willReturn($monthClose);

        $recalculateAction = $this->createMock(RecalculateSalesDocumentsCostPriceAction::class);
        $recalculateAction->expects(self::never())->method('__invoke');
        $analyticsFacade = $this->createMock(MarketplaceAnalyticsFacade::class);
        $analyticsFacade->expects(self::never())->method('recalculateSnapshotsForListings');
        $rebuildAction = $this->createMock(RebuildPreliminaryForPeriodAction::class);
        $rebuildAction->expects(self::never())->method('__invoke');

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects(self::once())->method('acquire')->willReturn(true);
        $lock->expects(self::once())->method('release');
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLock')->willReturn($lock);

        $handler = new RecalculateListingCostPriceHandler(
            $listingRepository,
            $monthCloseRepository,
            $recalculateAction,
            $analyticsFacade,
            $rebuildAction,
            $lockFactory,
            new NullLogger(),
        );

        $handler(new RecalculateListingCostPriceMessage(
            companyId: self::COMPANY_ID,
            marketplace: MarketplaceType::OZON->value,
            listingIds: [self::LISTING_ID],
            dateFrom: '2026-08-01',
            dateTo: '2026-08-23',
            actorUserId: self::ACTOR_ID,
        ));
    }

    public function testDoesNotCreatePreliminaryWhenPeriodHasNoExistingPreliminary(): void
    {
        $listing = $this->createMock(MarketplaceListing::class);
        $listing->method('getId')->willReturn(self::LISTING_ID);
        $listingRepository = $this->createMock(MarketplaceListingRepository::class);
        $listingRepository->method('findAllByCompanyMarketplaceAndIds')->willReturn([$listing]);

        $recalculateAction = $this->createMock(RecalculateSalesDocumentsCostPriceAction::class);
        $recalculateAction->expects(self::once())
            ->method('__invoke')
            ->willReturn(['sales' => 1, 'returns' => 0]);
        $analyticsFacade = $this->createMock(MarketplaceAnalyticsFacade::class);
        $analyticsFacade->expects(self::once())->method('recalculateSnapshotsForListings');
        $rebuildAction = $this->createMock(RebuildPreliminaryForPeriodAction::class);
        $rebuildAction->expects(self::never())->method('__invoke');

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects(self::once())->method('acquire')->willReturn(true);
        $lock->expects(self::exactly(2))->method('refresh');
        $lock->expects(self::once())->method('release');
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLock')->willReturn($lock);

        $handler = new RecalculateListingCostPriceHandler(
            $listingRepository,
            $this->createMock(MarketplaceMonthCloseRepository::class),
            $recalculateAction,
            $analyticsFacade,
            $rebuildAction,
            $lockFactory,
            new NullLogger(),
        );

        $handler(new RecalculateListingCostPriceMessage(
            companyId: self::COMPANY_ID,
            marketplace: MarketplaceType::OZON->value,
            listingIds: [self::LISTING_ID],
            dateFrom: '2026-08-01',
            dateTo: '2026-08-01',
            actorUserId: self::ACTOR_ID,
        ));
    }

    public function testSkipsWhenAllRequestedListingsAreMissingOrInaccessible(): void
    {
        $listingRepository = $this->createMock(MarketplaceListingRepository::class);
        $listingRepository->expects(self::once())
            ->method('findAllByCompanyMarketplaceAndIds')
            ->willReturn([]);
        $recalculateAction = $this->createMock(RecalculateSalesDocumentsCostPriceAction::class);
        $recalculateAction->expects(self::never())->method('__invoke');
        $analyticsFacade = $this->createMock(MarketplaceAnalyticsFacade::class);
        $analyticsFacade->expects(self::never())->method('recalculateSnapshotsForListings');
        $rebuildAction = $this->createMock(RebuildPreliminaryForPeriodAction::class);
        $rebuildAction->expects(self::never())->method('__invoke');
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->expects(self::never())->method('createLock');

        $handler = new RecalculateListingCostPriceHandler(
            $listingRepository,
            $this->createMock(MarketplaceMonthCloseRepository::class),
            $recalculateAction,
            $analyticsFacade,
            $rebuildAction,
            $lockFactory,
            new NullLogger(),
        );

        $handler(new RecalculateListingCostPriceMessage(
            companyId: self::COMPANY_ID,
            marketplace: MarketplaceType::OZON->value,
            listingIds: [self::LISTING_ID],
            dateFrom: '2026-08-01',
            dateTo: '2026-08-23',
            actorUserId: self::ACTOR_ID,
        ));
    }

    public function testRejectsCrossMonthPeriod(): void
    {
        $listingRepository = $this->createMock(MarketplaceListingRepository::class);
        $listingRepository->expects(self::never())->method('findAllByCompanyMarketplaceAndIds');
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->expects(self::never())->method('createLock');

        $handler = new RecalculateListingCostPriceHandler(
            $listingRepository,
            $this->createMock(MarketplaceMonthCloseRepository::class),
            $this->createMock(RecalculateSalesDocumentsCostPriceAction::class),
            $this->createMock(MarketplaceAnalyticsFacade::class),
            $this->createMock(RebuildPreliminaryForPeriodAction::class),
            $lockFactory,
            new NullLogger(),
        );

        $this->expectException(UnrecoverableMessageHandlingException::class);

        $handler(new RecalculateListingCostPriceMessage(
            companyId: self::COMPANY_ID,
            marketplace: MarketplaceType::OZON->value,
            listingIds: [self::LISTING_ID],
            dateFrom: '2026-08-01',
            dateTo: '2026-09-01',
            actorUserId: self::ACTOR_ID,
        ));
    }
}
