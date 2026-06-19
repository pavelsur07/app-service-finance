<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Messenger;

use App\Ingestion\Application\Command\MarkJobFailedCommand;
use App\Ingestion\Facade\SyncFacade;
use App\Ingestion\Message\RunSyncChunkMessage;
use App\Ingestion\Repository\SyncJobRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

final readonly class SyncJobFailureSubscriber implements EventSubscriberInterface
{
    private const REASON_MAX_LENGTH = 2000;

    public function __construct(
        private SyncJobRepository $syncJobRepository,
        private SyncFacade $syncFacade,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageFailedEvent::class => 'onMessageFailed',
        ];
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }

        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof RunSyncChunkMessage) {
            return;
        }

        $job = $this->syncJobRepository->findByIdAndCompany($message->jobId, $message->companyId);
        if (null === $job || $job->getStatus()->isTerminal()) {
            return;
        }

        $rootCause = $this->rootCause($event->getThrowable());
        $reason = $this->failureReason($rootCause);

        try {
            $this->syncFacade->markJobFailed(new MarkJobFailedCommand(
                jobId: $message->jobId,
                companyId: $message->companyId,
                reason: $reason,
            ));
        } catch (\Throwable $exception) {
            $this->logger->error('Ingestion sync job exhausted retries but failure state could not be persisted.', [
                'companyId' => $message->companyId,
                'jobId' => $message->jobId,
                'exceptionClass' => $exception::class,
                'errorMessage' => $exception->getMessage(),
            ]);

            return;
        }

        $this->logger->warning('Ingestion sync job marked as failed after retries exhausted.', [
            'companyId' => $message->companyId,
            'jobId' => $message->jobId,
            'messageType' => $message::class,
            'errorClass' => $rootCause::class,
            'errorMessage' => $rootCause->getMessage(),
        ]);
    }

    private function rootCause(\Throwable $throwable): \Throwable
    {
        if ($throwable instanceof HandlerFailedException && null !== $throwable->getPrevious()) {
            return $throwable->getPrevious();
        }

        return $throwable;
    }

    private function failureReason(\Throwable $throwable): string
    {
        $reason = $throwable::class;
        if ('' !== $throwable->getMessage()) {
            $reason .= ': '.$throwable->getMessage();
        }

        if (mb_strlen($reason, 'UTF-8') > self::REASON_MAX_LENGTH) {
            return mb_substr($reason, 0, self::REASON_MAX_LENGTH, 'UTF-8');
        }

        return $reason;
    }
}
