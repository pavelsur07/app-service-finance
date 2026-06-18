<?php

declare(strict_types=1);

namespace App\Finance\Facade;

use App\Finance\Application\Action\MarkPnlPeriodDirtyAction;
use App\Finance\Application\Action\RebuildPnlPeriodAction;
use App\Finance\Application\Command\MarkPnlPeriodDirtyCommand;
use App\Finance\Application\Command\RebuildPnlPeriodCommand;
use App\Finance\Application\DTO\PnlProgressView;
use App\Ingestion\Application\DTO\PLDirtyPeriodView;
use App\Ingestion\Enum\PLDirtyPeriodStatus;
use App\Ingestion\Repository\PLDirtyPeriodRepository;

final readonly class PnlFacade
{
    public function __construct(
        private MarkPnlPeriodDirtyAction $markDirtyAction,
        private RebuildPnlPeriodAction $rebuildAction,
        private PLDirtyPeriodRepository $dirtyPeriodRepository,
    ) {
    }

    public function markPeriodDirty(MarkPnlPeriodDirtyCommand $command): void
    {
        ($this->markDirtyAction)($command);
    }

    public function rebuildPeriod(RebuildPnlPeriodCommand $command): void
    {
        ($this->rebuildAction)($command);
    }

    /**
     * @return list<PLDirtyPeriodView>
     */
    public function getDirtyPeriods(string $companyId): array
    {
        return array_map(
            static fn ($period): PLDirtyPeriodView => PLDirtyPeriodView::fromEntity($period),
            $this->dirtyPeriodRepository->findForCompany($companyId),
        );
    }

    public function getProgress(string $companyId): PnlProgressView
    {
        return new PnlProgressView(
            pending: $this->dirtyPeriodRepository->countByStatus($companyId, PLDirtyPeriodStatus::PENDING),
            rebuilding: $this->dirtyPeriodRepository->countByStatus($companyId, PLDirtyPeriodStatus::REBUILDING),
            done: $this->dirtyPeriodRepository->countByStatus($companyId, PLDirtyPeriodStatus::DONE),
            failed: $this->dirtyPeriodRepository->countByStatus($companyId, PLDirtyPeriodStatus::FAILED),
            blockedByClose: $this->dirtyPeriodRepository->countByStatus($companyId, PLDirtyPeriodStatus::BLOCKED_BY_CLOSE),
        );
    }
}
