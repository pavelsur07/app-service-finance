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
use App\Ingestion\Enum\RawNormalizationStatus;
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
use App\Marketplace\Facade\MarketplaceFacade;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler]
final readonly class RunSyncChunkHandler
{
    private const MAX_RATE_LIMIT_ATTEMPTS = 12;

    public function __construct(
        private SyncJobRepository $syncJobRepository,
        private IngestCursorRepository $cursorRepository,
        private ConnectorRegistry $connectorRegistry,
        private RawStorageFacade $rawStorageFacade,
        private SyncFacade $syncFacade,
        private MarketplaceFacade $marketplaceFacade,
        private IngestRateLimitGuard $rateLimitGuard,
        private EntityManagerInterface $entityManager,
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
            $connector = $this->connectorRegistry->get($job->getSource(), $job->getResourceType());
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

                if (null !== $result->rawBatch) {
                    $records = $this->rawStorageFacade->store($result->rawBatch);
                    if ($result->normalizeRawRecords) {
                        foreach ($records as $record) {
                            if (RawNormalizationStatus::DONE === $record->getNormalizationStatus()) {
                                continue;
                            }

                            $this->messageBus->dispatch(new NormalizeRawRecordMessage($record->getId(), $record->getCompanyId()));
                        }
                    } else {
                        $this->markNormalizationSkipped($records);
                    }
                } elseif (!$result->hasMore) {
                    throw new \RuntimeException('Ingestion connector returned no raw batch without continuation.');
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

                // Отметка живости на границе итерации.
                //
                // Уборщик зависших задач (app:ingestion:reap-stale-jobs) считает
                // признаком отсутствия движения updatedAt, а тот менялся только
                // при смене статуса — то есть один раз на старте. Многочасовой
                // backfill без этой строки выглядел бы зависшим и был бы убран
                // на живом ходу.
                $job->heartbeat();
                $this->entityManager->flush();
            } while ($result->hasMore);

            $this->syncFacade->markJobCompleted(new MarkJobCompletedCommand($job->getId(), $job->getCompanyId()));
            $this->recordConnectorAuthSuccess($job);
        } catch (ConnectorAuthException $exception) {
            $this->markJobFailed($job->getId(), $job->getCompanyId(), 'auth');
            $this->recordConnectorAuthFailure($job);

            throw new UnrecoverableMessageHandlingException('Ingestion connector authentication failed.', 0, $exception);
        } catch (ConnectorRateLimitedException $exception) {
            if ($job->getAttempts() >= self::MAX_RATE_LIMIT_ATTEMPTS) {
                $reason = sprintf('rate_limit_exhausted_after_%d_attempts', $job->getAttempts());
                $this->logger->warning('Ingestion connector rate-limited too many times; job failed.', [
                    'companyId' => $job->getCompanyId(),
                    'jobId' => $job->getId(),
                    'source' => $job->getSource()->value,
                    'resourceType' => $job->getResourceType(),
                    'attempts' => $job->getAttempts(),
                    'retryAfterSeconds' => $exception->retryAfterSeconds(),
                ]);
                $this->markJobFailed($job->getId(), $job->getCompanyId(), $reason);

                return;
            }

            $this->logger->info('Ingestion connector rate-limited; chunk continuation scheduled.', [
                'companyId' => $job->getCompanyId(),
                'jobId' => $job->getId(),
                'source' => $job->getSource()->value,
                'resourceType' => $job->getResourceType(),
                'retryAfterSeconds' => $exception->retryAfterSeconds(),
            ]);
            $this->dispatchContinuation($message, $cursorValue, $exception->retryAfterSeconds());

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
            // Transient/unexpected failures must NOT terminate the job here: doing so
            // turned every deadlock, socket drop or OOM into an immediate FAILED, and
            // the terminal-status early return above made Messenger retries a no-op.
            // Rethrow instead so the retry strategy applies; SyncJobFailureSubscriber
            // marks the job FAILED once retries are exhausted.
            $this->logger->warning('Ingestion sync chunk failed; message will be retried.', [
                'companyId' => $job->getCompanyId(),
                'jobId' => $job->getId(),
                'exceptionClass' => $exception::class,
                'errorMessage' => $exception->getMessage(),
            ]);

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

    /**
     * Отметить отказ аутентификации на подключении.
     *
     * `error` пишется ровно один раз — в момент перехода подключения в
     * сломанное состояние. Это единственное здесь событие, требующее человека:
     * загрузка остановлена и не возобновится, пока ключ не заменят. Остальные
     * отказы остаются `warning` и несут контекст (компания, подключение,
     * ресурс), которого нет в системных записях.
     *
     * Важно не обманываться насчёт тишины: сам факт отказа всё равно доедет до
     * GlitchTip помимо этих строк. Обработчик бросает
     * `UnrecoverableMessageHandlingException`, а Messenger на неретраящемся
     * сбое пишет `critical` (`SendFailedMessageForRetryListener`), и Sentry при
     * `capture_soft_fails: false` ловит именно такие, «жёсткие», сбои. Шум
     * убирает не уровень записи, а остановка планировщика: после перехода крон
     * перестаёт ставить задания, и поток сообщений прекращается.
     */
    private function recordConnectorAuthFailure(SyncJob $job): void
    {
        $becameBroken = $this->guardAuthStateWrite(
            fn (): bool => $this->marketplaceFacade->recordConnectorAuthFailure(
                $job->getCompanyId(),
                $job->getConnectionRef(),
            ),
            $job,
        );

        $context = [
            'companyId' => $job->getCompanyId(),
            'jobId' => $job->getId(),
            'source' => $job->getSource()->value,
            'resourceType' => $job->getResourceType(),
            'connectionRef' => $job->getConnectionRef(),
        ];

        if ($becameBroken) {
            $this->logger->error('Marketplace connection stopped accepting the API key; ingestion halted for it.', $context);

            return;
        }

        $this->logger->warning('Ingestion connector rejected the API key.', $context);
    }

    private function recordConnectorAuthSuccess(SyncJob $job): void
    {
        $recovered = $this->guardAuthStateWrite(
            fn (): bool => $this->marketplaceFacade->recordConnectorAuthSuccess(
                $job->getCompanyId(),
                $job->getConnectionRef(),
            ),
            $job,
        );

        if ($recovered) {
            $this->logger->info('Marketplace connection accepts the API key again; ingestion resumed.', [
                'companyId' => $job->getCompanyId(),
                'source' => $job->getSource()->value,
                'connectionRef' => $job->getConnectionRef(),
            ]);
        }
    }

    /**
     * Учёт состояния не имеет права подменить исходную причину и не имеет
     * права шуметь.
     *
     * Вызов из ветки отказа идёт внутри `catch`, и брошенное отсюда исключение
     * заменило бы собой ошибку API — ту самую, ради диагностики которой всё и
     * пишется. Ловится `\Throwable` — тем же приёмом, что и у
     * {@see self::markJobFailed()} выше.
     *
     * `connectionRef` контрактом задания гарантирован лишь непустым, и не
     * каждое задание ссылается на строку реестра подключений. Для таких
     * заданий состояние обновлять просто не на чем, и это НЕ ошибка: путь
     * успеха проходит на каждом завершённом чанке, поэтому запись `error`
     * здесь означала бы поток ложных алертов в GlitchTip на каждой удачной
     * синхронизации. Такие задания молча пропускаются.
     *
     * @param callable(): bool $write
     */
    private function guardAuthStateWrite(callable $write, SyncJob $job): bool
    {
        if (!Uuid::isValid($job->getConnectionRef())) {
            return false;
        }

        try {
            return $write();
        } catch (\Throwable $exception) {
            $this->logger->error('Connector auth state could not be recorded on the marketplace connection.', [
                'companyId' => $job->getCompanyId(),
                'jobId' => $job->getId(),
                'connectionRef' => $job->getConnectionRef(),
                'exceptionClass' => $exception::class,
                'errorMessage' => $exception->getMessage(),
            ]);

            return false;
        }
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

    /**
     * @param list<\App\Ingestion\Entity\IngestRawRecord> $records
     */
    private function markNormalizationSkipped(array $records): void
    {
        if ([] === $records) {
            return;
        }

        foreach ($records as $record) {
            $record->markNormalizationSkipped();
        }

        $this->entityManager->flush();
    }

    private function dispatchContinuation(RunSyncChunkMessage $message, ?string $cursorValue, int $delaySeconds): void
    {
        $this->messageBus->dispatch(
            new RunSyncChunkMessage($message->companyId, $message->jobId, $cursorValue),
            [new DelayStamp(max(1, $delaySeconds) * 1000)],
        );
    }
}
