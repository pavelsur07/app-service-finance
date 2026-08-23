<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Marketplace\Application\Command\RebuildPreliminaryForPeriodCommand;
use App\Marketplace\Application\RebuildPreliminaryForPeriodAction;
use App\Marketplace\Application\RecalculateSalesDocumentsCostPriceAction;
use App\Marketplace\DTO\RecalculateSalesCostPriceCommand;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\MonthCloseStageStatus;
use App\Marketplace\Message\RecalculateListingCostPriceMessage;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Marketplace\Repository\MarketplaceMonthCloseRepository;
use App\MarketplaceAnalytics\Facade\MarketplaceAnalyticsFacade;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final readonly class RecalculateListingCostPriceHandler
{
    private const LOCK_TTL_SECONDS = 900;
    private const SNAPSHOT_CHUNK_SIZE = 25;

    public function __construct(
        private MarketplaceListingRepository $listingRepository,
        private MarketplaceMonthCloseRepository $monthCloseRepository,
        private RecalculateSalesDocumentsCostPriceAction $recalculateAction,
        private MarketplaceAnalyticsFacade $marketplaceAnalyticsFacade,
        private RebuildPreliminaryForPeriodAction $rebuildPreliminaryAction,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RecalculateListingCostPriceMessage $message): void
    {
        $marketplace = MarketplaceType::tryFrom($message->marketplace);
        $dateFrom = self::parseDate($message->dateFrom);
        $dateTo = self::parseDate($message->dateTo);

        if (
            null === $marketplace
            || null === $dateFrom
            || null === $dateTo
            || $dateFrom > $dateTo
            || $dateFrom->format('Y-m') !== $dateTo->format('Y-m')
            || '01' !== $dateFrom->format('d')
            || !Uuid::isValid($message->companyId)
            || !Uuid::isValid($message->actorUserId)
            || [] === $message->listingIds
            || array_any(
                $message->listingIds,
                static fn (mixed $listingId): bool => !is_string($listingId) || !Uuid::isValid($listingId),
            )
        ) {
            throw new UnrecoverableMessageHandlingException('Invalid listing cost recalculation message.');
        }

        $listingIds = array_values(array_unique($message->listingIds));

        $listings = $this->listingRepository->findAllByCompanyMarketplaceAndIds(
            $message->companyId,
            $marketplace,
            $listingIds,
        );

        $resolvedListingIds = array_map(
            static fn (MarketplaceListing $listing): string => $listing->getId(),
            $listings,
        );
        if (count($resolvedListingIds) !== count($listingIds)) {
            $this->logger->warning('[InventoryCostRecalculation] Missing or inaccessible listings skipped', [
                'company_id' => $message->companyId,
                'marketplace' => $marketplace->value,
                'requested_count' => count($listingIds),
                'resolved_count' => count($resolvedListingIds),
            ]);
        }
        if ([] === $resolvedListingIds) {
            return;
        }

        $listingIds = $resolvedListingIds;

        $lock = $this->lockFactory->createLock(
            sprintf(
                'marketplace-cost-recalculation-%s-%s-%s',
                $message->companyId,
                $marketplace->value,
                $dateFrom->format('Y-m'),
            ),
            self::LOCK_TTL_SECONDS,
        );

        if (!$lock->acquire()) {
            throw new RecoverableMessageHandlingException('Concurrent listing cost recalculation is still running.', retryDelay: 5000);
        }

        $startedAt = microtime(true);

        try {
            $monthClose = $this->monthCloseRepository->findByPeriod(
                $message->companyId,
                $marketplace,
                (int) $dateFrom->format('Y'),
                (int) $dateFrom->format('n'),
            );
            if (
                null !== $monthClose
                && MonthCloseStageStatus::CLOSED === $monthClose->getStageStatus(CloseStage::SALES_RETURNS)
                && !$monthClose->isStageLastCloseWasPreliminary(CloseStage::SALES_RETURNS)
            ) {
                $this->logger->info('[InventoryCostRecalculation] Final month close is immutable, skipped', [
                    'company_id' => $message->companyId,
                    'marketplace' => $marketplace->value,
                    'year' => (int) $dateFrom->format('Y'),
                    'month' => (int) $dateFrom->format('n'),
                ]);

                return;
            }
            $shouldRebuildPreliminary = null !== $monthClose
                && MonthCloseStageStatus::CLOSED === $monthClose->getStageStatus(CloseStage::SALES_RETURNS)
                && $monthClose->isStageLastCloseWasPreliminary(CloseStage::SALES_RETURNS);
            $previousPreliminaryDocumentIds = $shouldRebuildPreliminary
                ? $monthClose->getStagePLDocumentIds(CloseStage::SALES_RETURNS)
                : [];

            $result = ($this->recalculateAction)(new RecalculateSalesCostPriceCommand(
                companyId: $message->companyId,
                marketplace: $marketplace,
                dateFrom: $dateFrom,
                dateTo: $dateTo,
                listingIds: $listingIds,
            ));

            foreach (array_chunk($listingIds, self::SNAPSHOT_CHUNK_SIZE) as $snapshotListingIds) {
                $this->marketplaceAnalyticsFacade->recalculateSnapshotsForListings(
                    $message->companyId,
                    $snapshotListingIds,
                    $dateFrom,
                    $dateTo,
                    $marketplace->value,
                );
                $lock->refresh();
            }
            $lock->refresh();

            $preliminaryRebuilt = null;
            if ($shouldRebuildPreliminary) {
                ($this->rebuildPreliminaryAction)(new RebuildPreliminaryForPeriodCommand(
                    companyId: $message->companyId,
                    marketplace: $marketplace->value,
                    year: (int) $dateFrom->format('Y'),
                    month: (int) $dateFrom->format('n'),
                    actorUserId: $message->actorUserId,
                    stages: [CloseStage::SALES_RETURNS->value],
                ));

                $rebuiltMonthClose = $this->monthCloseRepository->findByPeriod(
                    $message->companyId,
                    $marketplace,
                    (int) $dateFrom->format('Y'),
                    (int) $dateFrom->format('n'),
                );
                $preliminaryRebuilt = null !== $rebuiltMonthClose
                    && MonthCloseStageStatus::CLOSED === $rebuiltMonthClose->getStageStatus(CloseStage::SALES_RETURNS)
                    && $rebuiltMonthClose->isStageLastCloseWasPreliminary(CloseStage::SALES_RETURNS)
                    && $previousPreliminaryDocumentIds !== $rebuiltMonthClose->getStagePLDocumentIds(CloseStage::SALES_RETURNS);
            }

            $this->logger->info('[InventoryCostRecalculation] Completed', [
                'company_id' => $message->companyId,
                'marketplace' => $marketplace->value,
                'listing_count' => count($listingIds),
                'date_from' => $message->dateFrom,
                'date_to' => $message->dateTo,
                'sales' => $result['sales'],
                'returns' => $result['returns'],
                'preliminary_rebuilt' => $preliminaryRebuilt,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[InventoryCostRecalculation] Failed', [
                'company_id' => $message->companyId,
                'marketplace' => $message->marketplace,
                'listing_count' => count($listingIds),
                'date_from' => $message->dateFrom,
                'date_to' => $message->dateTo,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $lock->release();
        }
    }

    private static function parseDate(string $date): ?\DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return false !== $parsed && $parsed->format('Y-m-d') === $date ? $parsed : null;
    }
}
