<?php

declare(strict_types=1);

namespace App\Ingestion\MessageHandler;

use App\Ingestion\Application\Command\MarkJobCompletedCommand;
use App\Ingestion\Application\Command\MarkJobFailedCommand;
use App\Ingestion\Application\Command\MarkJobRunningCommand;
use App\Ingestion\Application\Command\UpdateCursorCommand;
use App\Ingestion\Application\DTO\PullRequest;
use App\Ingestion\Application\Service\IngestRateLimitGuard;
use App\Ingestion\Domain\Service\ConnectorRegistry;
use App\Ingestion\Exception\ConnectorAuthException;
use App\Ingestion\Exception\ConnectorTransientException;
use App\Ingestion\Exception\SyncJobNotFoundException;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Facade\SyncFacade;
use App\Ingestion\Message\NormalizeRawRecordMessage;
use App\Ingestion\Message\RunSyncChunkMessage;
use App\Ingestion\Repository\IngestCursorRepository;
use App\Ingestion\Repository\SyncJobRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class RunSyncChunkHandler
{
    public function __construct(
        private SyncJobRepository $syncJobRepository,
        private IngestCursorRepository $cursorRepository,
        private ConnectorRegistry $connectorRegistry,
        private RawStorageFacade $rawStorageFacade,
        private SyncFacade $syncFacade,
        private IngestRateLimitGuard $rateLimitGuard,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RunSyncChunkMessage $message): void
    {
        $job = $this->syncJobRepository->findByIdAndCompany($message->jobId, $message->companyId);
        if (null === $job) {
            throw new SyncJobNotFoundException('Sync job was not found.');
        }

        if ($job->getStatus()->isTerminal()) {
            $this->logger->info('Ingestion sync chunk skipped because job is terminal.', [
                'companyId' => $message->companyId,
                'jobId' => $message->jobId,
                'status' => $job->getStatus()->value,
            ]);

            return;
        }

        $cursor = $this->cursorRepository->findOne(
            $job->getCompanyId(),
            $job->getConnectionRef(),
            $job->getResourceType(),
            $job->getShopRef(),
        );
        $cursorValue = $cursor?->getCursorValue();
        $cursorSnapshot = null === $job->getStartedAt() && null !== $cursorValue && '' !== $cursorValue
            ? $cursorValue
            : null;

        $this->syncFacade->markJobRunning(new MarkJobRunningCommand(
            jobId: $job->getId(),
            companyId: $job->getCompanyId(),
            cursorSnapshot: $cursorSnapshot,
        ));

        $lock = null;

        try {
            $connector = $this->connectorRegistry->get($job->getSource());
            $lock = $this->rateLimitGuard->acquire(sprintf('%s:%s', $job->getSource()->value, $job->getConnectionRef()));
            $result = $connector->pull(new PullRequest(
                companyId: $job->getCompanyId(),
                connectionRef: $job->getConnectionRef(),
                shopRef: $job->getShopRef(),
                resourceType: $job->getResourceType(),
                cursorValue: $cursorValue,
                windowFrom: $job->getWindowFrom(),
                windowTo: $job->getWindowTo(),
                syncJobId: $job->getId(),
            ));

            $records = $this->rawStorageFacade->store($result->rawBatch);
            foreach ($records as $record) {
                $this->messageBus->dispatch(new NormalizeRawRecordMessage($record->getId(), $record->getCompanyId()));
            }

            if (null !== $result->nextCursorValue && '' !== $result->nextCursorValue) {
                $this->syncFacade->updateCursor(new UpdateCursorCommand(
                    companyId: $job->getCompanyId(),
                    connectionRef: $job->getConnectionRef(),
                    resourceType: $job->getResourceType(),
                    shopRef: $job->getShopRef(),
                    newCursorValue: $result->nextCursorValue,
                    syncJobId: $job->getId(),
                    fetchedAt: new \DateTimeImmutable(),
                ));
            }

            $this->syncFacade->markJobCompleted(new MarkJobCompletedCommand($job->getId(), $job->getCompanyId()));
        } catch (ConnectorAuthException $exception) {
            $this->markJobFailed($job->getId(), $job->getCompanyId(), 'auth');

            throw new UnrecoverableMessageHandlingException('Ingestion connector authentication failed.', 0, $exception);
        } catch (ConnectorTransientException $exception) {
            $this->logger->warning('Ingestion connector transient failure; message will be retried.', [
                'companyId' => $job->getCompanyId(),
                'jobId' => $job->getId(),
                'exceptionClass' => $exception::class,
                'errorMessage' => $exception->getMessage(),
            ]);

            throw $exception;
        } catch (\Throwable $exception) {
            $this->markJobFailed($job->getId(), $job->getCompanyId(), $this->failureReason($exception));

            throw $exception;
        } finally {
            $this->releaseLock($lock);
        }
    }

    private function markJobFailed(string $jobId, string $companyId, string $reason): void
    {
        try {
            $this->syncFacade->markJobFailed(new MarkJobFailedCommand($jobId, $companyId, $reason));
        } catch (\Throwable $exception) {
            $this->logger->error('Ingestion sync job failed but failure state could not be persisted.', [
                'companyId' => $companyId,
                'jobId' => $jobId,
                'exceptionClass' => $exception::class,
                'errorMessage' => $exception->getMessage(),
            ]);
        }
    }

    private function failureReason(\Throwable $exception): string
    {
        $message = $exception->getMessage();
        if ('' === $message) {
            $message = $exception::class;
        }

        return substr($message, 0, 2000);
    }

    private function releaseLock(?LockInterface $lock): void
    {
        if (null === $lock) {
            return;
        }

        try {
            $lock->release();
        } catch (\Throwable $exception) {
            $this->logger->warning('Ingestion rate-limit lock release failed.', [
                'exceptionClass' => $exception::class,
                'errorMessage' => $exception->getMessage(),
            ]);
        }
    }
}
