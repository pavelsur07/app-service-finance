<?php

declare(strict_types=1);

namespace App\Ingestion\Facade;

use App\Ingestion\Application\Action\MarkJobCompletedAction;
use App\Ingestion\Application\Action\MarkJobFailedAction;
use App\Ingestion\Application\Action\MarkJobRunningAction;
use App\Ingestion\Application\Action\SplitJobIntoChunksAction;
use App\Ingestion\Application\Action\StartBackfillAction;
use App\Ingestion\Application\Action\StartIncrementalAction;
use App\Ingestion\Application\Action\UpdateCursorAction;
use App\Ingestion\Application\Command\MarkJobCompletedCommand;
use App\Ingestion\Application\Command\MarkJobFailedCommand;
use App\Ingestion\Application\Command\MarkJobRunningCommand;
use App\Ingestion\Application\Command\SplitJobCommand;
use App\Ingestion\Application\Command\StartBackfillCommand;
use App\Ingestion\Application\Command\StartIncrementalCommand;
use App\Ingestion\Application\Command\UpdateCursorCommand;
use App\Ingestion\Application\DTO\SyncJobProgressView;
use App\Ingestion\Application\Source\Wildberries\WbResourceType;
use App\Ingestion\Exception\SyncJobNotFoundException;
use App\Ingestion\Repository\SyncJobRepository;

final readonly class SyncFacade
{
    public function __construct(
        private StartBackfillAction $startBackfillAction,
        private StartIncrementalAction $startIncrementalAction,
        private SplitJobIntoChunksAction $splitJobIntoChunksAction,
        private MarkJobRunningAction $markJobRunningAction,
        private MarkJobCompletedAction $markJobCompletedAction,
        private MarkJobFailedAction $markJobFailedAction,
        private UpdateCursorAction $updateCursorAction,
        private SyncJobRepository $syncJobRepository,
    ) {
    }

    public function startBackfill(StartBackfillCommand $command): string
    {
        $parentJobId = ($this->startBackfillAction)($command);
        ($this->splitJobIntoChunksAction)(new SplitJobCommand(
            $parentJobId,
            $command->companyId,
            WbResourceType::FINANCE_SALES_REPORT_DETAILED === $command->resourceType ? 1 : 7,
        ));

        return $parentJobId;
    }

    public function startIncremental(StartIncrementalCommand $command): string
    {
        return ($this->startIncrementalAction)($command);
    }

    public function markJobRunning(MarkJobRunningCommand $command): void
    {
        ($this->markJobRunningAction)($command);
    }

    public function markJobCompleted(MarkJobCompletedCommand $command): void
    {
        ($this->markJobCompletedAction)($command);
    }

    public function markJobFailed(MarkJobFailedCommand $command): void
    {
        ($this->markJobFailedAction)($command);
    }

    public function updateCursor(UpdateCursorCommand $command): void
    {
        ($this->updateCursorAction)($command);
    }

    public function getProgress(string $jobId, string $companyId): SyncJobProgressView
    {
        $job = $this->syncJobRepository->findByIdAndCompany($jobId, $companyId);
        if (null === $job) {
            throw new SyncJobNotFoundException('Sync job was not found.');
        }

        return new SyncJobProgressView(
            jobId: $job->getId(),
            status: $job->getStatus(),
            progressDone: $job->getProgressDone(),
            progressTotal: $job->getProgressTotal(),
            attempts: $job->getAttempts(),
            lastError: $job->getLastError(),
        );
    }
}
