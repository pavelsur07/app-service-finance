<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Action;

use App\Ingestion\Enum\SyncJobStatus;
use App\Ingestion\Exception\SyncJobNotFoundException;
use App\Ingestion\Repository\SyncJobRepository;

final readonly class MaybeFinalizeParentAction
{
    public function __construct(private SyncJobRepository $syncJobRepository)
    {
    }

    public function __invoke(string $parentJobId, string $companyId): void
    {
        $parent = $this->syncJobRepository->findByIdAndCompany($parentJobId, $companyId);
        if (null === $parent) {
            throw new SyncJobNotFoundException('Parent sync job was not found.');
        }

        $completed = $this->syncJobRepository->countChildrenByStatus($parentJobId, $companyId, SyncJobStatus::COMPLETED);
        $failed = $this->syncJobRepository->countChildrenByStatus($parentJobId, $companyId, SyncJobStatus::FAILED);
        $cancelled = $this->syncJobRepository->countChildrenByStatus($parentJobId, $companyId, SyncJobStatus::CANCELLED);
        $terminal = $completed + $failed + $cancelled;

        if ($parent->getProgressTotal() <= 0 || $terminal < $parent->getProgressTotal()) {
            if ($terminal > $parent->getProgressDone()) {
                $parent->incrementProgress($terminal - $parent->getProgressDone());
            }

            return;
        }

        if ($terminal > $parent->getProgressDone()) {
            $parent->incrementProgress($terminal - $parent->getProgressDone());
        }

        if (SyncJobStatus::OPEN === $parent->getStatus()) {
            $parent->markRunning();
        }

        if (0 === $failed && 0 === $cancelled) {
            $parent->markCompleted();

            return;
        }

        $parent->markFailed(sprintf('partial failure: %d failed, %d cancelled, %d completed', $failed, $cancelled, $completed));
    }
}
