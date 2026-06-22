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
use App\Ingestion\Entity\SyncJob;
use App\Ingestion\Exception\ConnectorAuthException;
use App\Ingestion\Exception\ConnectorRateLimitedException;
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
use Symfony\Component\Messenger\Stamp\DelayStamp;

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

        $isWindowed = $this->isWindowed($job);
        $cursorValue = $message->cursorValue ?? ($isWindowed ? null : $this->sharedCursorValue($job));
        $cursorSnapshot = !$isWindowed && null === $job->getStartedAt() && null !== $cursorValue && '' !== $cursorValue
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

            do {
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
                if ($result->normalizeRawRecords) {
                    foreach ($records as $record) {
                        $this->messageBus->dispatch(new NormalizeRawRecordMessage($record->getId(), $record->getCompanyId()));
                    }
                }

                if (null !== $result->nextCursorValue && '' !== $result->nextCursorValue) {
                    if ($result->hasMore && null !== $result->continuationDelaySeconds) {
                        $this->dispatchContinuation($message, $result->nextCursorValue, $result->continuationDelaySeconds);

                        return;
                    }

                    if (!$isWindowed) {
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

                    $cursorValue = $result->nextCursorValue;
                } elseif ($result->hasMore) {
                    throw new \RuntimeException('Ingestion connector returned hasMore without next cursor.');
                }
            } while ($result->hasMore);

            $this->syncFacade->markJobCompleted(new MarkJobCompletedCommand($job->getId(), $job->getCompanyId()));
        } catch (ConnectorAuthException $exception) {
            $this->markJobFailed($job->getId(), $job->getCompanyId(), 'auth');

            throw new UnrecoverableMessageHandlingException('Ingestion connector authentication failed.', 0, $exception);
        } catch (ConnectorRateLimitedException $exception) {
            $this->logger->info('Ingestion connector rate-limited; chunk continuation scheduled.', [
                'companyId' => $job->getCompanyId(),
                'jobId' => $job->getId(),
                'source' => $job->getSource()->value,
                'resourceType' => $job->getResourceType(),
                'retryAfterSeconds' => $exception->retryAfterSeconds(),
            ]);
            $this->dispatchContinuation($message, $message->cursorValue, $exception->retryAfterSeconds());

            return;
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

    private function sharedCursorValue(SyncJob $job): ?string
    {
        $cursor = $this->cursorRepository->findOne(
            $job->getCompanyId(),
            $job->getConnectionRef(),
            $job->getResourceType(),
            $job->getShopRef(),
        );

        return $cursor?->getCursorValue();
    }

    private function isWindowed(SyncJob $job): bool
    {
        return null !== $job->getWindowFrom() || null !== $job->getWindowTo();
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

    private function dispatchContinuation(RunSyncChunkMessage $message, ?string $cursorValue, int $delaySeconds): void
    {
        $this->messageBus->dispatch(
            new RunSyncChunkMessage($message->companyId, $message->jobId, $cursorValue),
            [new DelayStamp(max(1, $delaySeconds) * 1000)],
        );
    }
}
