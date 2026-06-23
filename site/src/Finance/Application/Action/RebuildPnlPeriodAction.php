<?php

declare(strict_types=1);

namespace App\Finance\Application\Action;

use App\Company\Entity\Company;
use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Finance\Application\Command\RebuildPnlPeriodCommand;
use App\Finance\Application\Service\PnlCategoryResolver;
use App\Finance\Application\Service\PnlPeriodResolver;
use App\Finance\Application\Service\PnlProjectDirectionResolver;
use App\Finance\Domain\Event\PnlClosedPeriodTouchedEvent;
use App\Finance\Exception\PnlRebuildLockTimeoutException;
use App\Finance\Repository\PLDailyTotalRepository;
use App\Finance\Repository\PLMonthlySnapshotRepository;
use App\Ingestion\Entity\PLDirtyPeriod;
use App\Ingestion\Enum\PLDirtyPeriodReason;
use App\Ingestion\Enum\PLDirtyPeriodStatus;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use App\Ingestion\Facade\IngestionFacade;
use App\Ingestion\Repository\PLDirtyPeriodRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Lock\LockFactory;

final readonly class RebuildPnlPeriodAction
{
    private const LOCK_TTL_SECONDS = 600.0;

    public function __construct(
        private PLDirtyPeriodRepository $dirtyPeriodRepository,
        private CompanyRepository $companyRepository,
        private PnlPeriodResolver $periodResolver,
        private PnlCategoryResolver $categoryResolver,
        private PnlProjectDirectionResolver $projectDirectionResolver,
        private MaybeBlockByClosePeriodAction $blockByClosePeriodAction,
        private IngestionFacade $ingestionFacade,
        private PLDailyTotalRepository $dailyTotalRepository,
        private PLMonthlySnapshotRepository $monthlySnapshotRepository,
        private EntityManagerInterface $entityManager,
        private EventDispatcherInterface $eventDispatcher,
        private LockFactory $lockFactory,
    ) {
    }

    public function __invoke(RebuildPnlPeriodCommand $command): void
    {
        $lock = $this->lockFactory->createLock($this->lockKey($command), self::LOCK_TTL_SECONDS, false);
        if (!$lock->acquire()) {
            throw new PnlRebuildLockTimeoutException(sprintf('P&L rebuild for company "%s" period %04d-%02d is already running.', $command->companyId, $command->year, $command->month));
        }

        try {
            $dirtyPeriod = $this->ensureDirtyPeriod($command);

            if ('' !== $command->shopRef) {
                $this->markFailed($dirtyPeriod, 'Source-scoped P&L rebuild is not supported until Finance source-linking is decided.');

                return;
            }

            $block = ($this->blockByClosePeriodAction)($command->companyId, $command->year, $command->month, $command->shopRef);
            if (null !== $block) {
                $this->markBlocked($dirtyPeriod, $block->reason, $command);

                return;
            }

            $this->runRebuild($command, $dirtyPeriod);
        } catch (\Throwable $exception) {
            $this->markFailedAfterException($command, $exception);

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    private function runRebuild(RebuildPnlPeriodCommand $command, PLDirtyPeriod $dirtyPeriod): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            if (PLDirtyPeriodStatus::DONE === $dirtyPeriod->getStatus() || PLDirtyPeriodStatus::FAILED === $dirtyPeriod->getStatus()) {
                $dirtyPeriod->reopen();
            }

            if (PLDirtyPeriodStatus::PENDING === $dirtyPeriod->getStatus()) {
                $dirtyPeriod->markRebuilding();
            }

            $company = $this->companyRepository->findById($command->companyId);
            if (!$company instanceof Company) {
                throw new \DomainException(sprintf('Company "%s" was not found.', $command->companyId));
            }

            $projectDirection = $this->projectDirectionResolver->resolveDefault($company);
            $projectDirectionId = $projectDirection->getId();
            if (null === $projectDirectionId) {
                throw new \LogicException('Resolved project direction does not have an id.');
            }

            [$from, $to] = $this->periodResolver->bounds($command->year, $command->month);
            $period = sprintf('%04d-%02d', $command->year, $command->month);
            $rebuiltAt = new \DateTimeImmutable();

            $this->dailyTotalRepository->deleteByCompanyShopAndMonth($command->companyId, $command->shopRef, $command->year, $command->month);
            $this->monthlySnapshotRepository->deleteByCompanyShopAndMonth($command->companyId, $command->shopRef, $command->year, $command->month);

            foreach ($this->ingestionFacade->getTransactions($command->companyId, $from, $to, null) as $transaction) {
                $direction = TransactionDirection::from($transaction->direction);
                $categoryId = $this->categoryResolver->resolve($command->companyId, TransactionType::from($transaction->type), $direction);
                $amount = $this->formatAmountMinor($transaction->amountMinor);
                $income = TransactionDirection::IN === $direction ? $amount : '0.00';
                $expense = TransactionDirection::OUT === $direction ? $amount : '0.00';

                $this->dailyTotalRepository->upsert(
                    companyId: $command->companyId,
                    categoryId: $categoryId,
                    date: $transaction->occurredAt->setTimezone(new \DateTimeZone('Europe/Moscow'))->setTime(0, 0),
                    projectDirectionId: $projectDirectionId,
                    amountIncome: $income,
                    amountExpense: $expense,
                    replace: false,
                    timestamp: $rebuiltAt,
                    rebuiltAt: $rebuiltAt,
                );

                $this->monthlySnapshotRepository->upsert(
                    companyId: $command->companyId,
                    categoryId: $categoryId,
                    period: $period,
                    amountIncome: $income,
                    amountExpense: $expense,
                    updatedAt: $rebuiltAt,
                    rebuiltAt: $rebuiltAt,
                    accumulate: true,
                );
            }

            $dirtyPeriod->markDone($rebuiltAt);
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }
    }

    private function ensureDirtyPeriod(RebuildPnlPeriodCommand $command): PLDirtyPeriod
    {
        $dirtyPeriod = $this->dirtyPeriodRepository->findOne($command->companyId, $command->year, $command->month, $command->shopRef);
        if ($dirtyPeriod instanceof PLDirtyPeriod) {
            return $dirtyPeriod;
        }

        $dirtyPeriod = new PLDirtyPeriod(
            companyId: $command->companyId,
            periodYear: $command->year,
            periodMonth: $command->month,
            shopRef: $command->shopRef,
            reason: PLDirtyPeriodReason::MANUAL,
        );

        $this->entityManager->persist($dirtyPeriod);
        $this->entityManager->flush();

        return $dirtyPeriod;
    }

    private function markBlocked(PLDirtyPeriod $dirtyPeriod, string $reason, RebuildPnlPeriodCommand $command): void
    {
        if (PLDirtyPeriodStatus::PENDING === $dirtyPeriod->getStatus()) {
            $dirtyPeriod->markBlockedByClose($reason);
        } elseif (PLDirtyPeriodStatus::REBUILDING === $dirtyPeriod->getStatus()) {
            $dirtyPeriod->markBlockedByClose($reason);
        }

        $this->eventDispatcher->dispatch(new PnlClosedPeriodTouchedEvent(
            companyId: $command->companyId,
            year: $command->year,
            month: $command->month,
            shopRef: $command->shopRef,
            reason: $reason,
        ));
        $this->entityManager->flush();
    }

    private function markFailed(PLDirtyPeriod $dirtyPeriod, string $reason): void
    {
        if (PLDirtyPeriodStatus::PENDING === $dirtyPeriod->getStatus()) {
            $dirtyPeriod->markRebuilding();
        }

        if (PLDirtyPeriodStatus::REBUILDING === $dirtyPeriod->getStatus()) {
            $dirtyPeriod->markFailed($reason);
            $this->entityManager->flush();
        }
    }

    private function markFailedAfterException(RebuildPnlPeriodCommand $command, \Throwable $exception): void
    {
        $dirtyPeriod = $this->dirtyPeriodRepository->findOne($command->companyId, $command->year, $command->month, $command->shopRef);
        if (!$dirtyPeriod instanceof PLDirtyPeriod) {
            return;
        }

        $this->markFailed($dirtyPeriod, $exception->getMessage());
    }

    private function lockKey(RebuildPnlPeriodCommand $command): string
    {
        return sprintf('pnl_rebuild:%s:%04d-%02d:%s', $command->companyId, $command->year, $command->month, $command->shopRef);
    }

    private function formatAmountMinor(int $amountMinor): string
    {
        return number_format($amountMinor / 100, 2, '.', '');
    }
}
