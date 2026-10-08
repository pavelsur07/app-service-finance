<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Messenger;

use App\Ingestion\Application\Command\MarkJobFailedCommand;
use App\Ingestion\Enum\SyncJobStatus;
use App\Ingestion\Exception\ConnectorTransientException;
use App\Ingestion\Facade\SyncFacade;
use App\Ingestion\Message\RunSyncChunkMessage;
use App\Ingestion\Repository\SyncJobRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;

final readonly class SyncJobFailureSubscriber implements EventSubscriberInterface
{
    private const REASON_MAX_LENGTH = 2000;

    /** Подряд упавших по 5xx/таймауту заданий одного ресурса до `error`: у почасового синка — около 3 часов. */
    public const TRANSIENT_STREAK_ALERT = 3;

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

        // Транзиентный отказ внешнего API (5xx, таймаут) — не инцидент для очереди
        // `failed`: задание уже FAILED с причиной, повтор сообщения был бы no-op
        // (терминальный статус), а следующий плановый запуск подхватит работу.
        // Стамп — штатный способ Messenger отказаться от failure transport; без
        // него каждый ночной сбой WB краснит гейт app:messenger:failed-queue-check.
        // Остальные исключения по-прежнему уходят в `failed`.
        if ($rootCause instanceof ConnectorTransientException) {
            $event->addStamps(new SentToFailureTransportStamp($event->getReceiverName()));
            $this->alertOnSustainedOutage($job->getCompanyId(), $job->getConnectionRef(), $job->getResourceType(), $job->getShopRef());
        }

        $this->logger->warning('Ingestion sync job marked as failed after retries exhausted.', [
            'companyId' => $message->companyId,
            'jobId' => $message->jobId,
            'messageType' => $message::class,
            'errorClass' => $rootCause::class,
            'errorMessage' => $rootCause->getMessage(),
        ]);
    }

    /**
     * Единственный сигнал GlitchTip о транзиентных отказах внешнего API.
     *
     * Сами отказы в GlitchTip не уходят (`ignore_exceptions` в sentry.yaml):
     * разовый 5xx маркетплейса лечится следующим плановым запуском, а раньше
     * каждый давал два события (#230, #384). Инцидент — когда ресурс не
     * загружается подряд TRANSIENT_STREAK_ALERT заданий. `error` пишется ровно
     * на пороге, а не на каждом следующем отказе серии.
     */
    private function alertOnSustainedOutage(string $companyId, string $connectionRef, string $resourceType, string $shopRef): void
    {
        // На одно задание больше порога: иначе серия длиннее порога упиралась бы
        // в лимит выборки, считалась равной ему и будила на каждом отказе.
        $streak = 0;
        foreach ($this->syncJobRepository->findRecentFinishedForResource($companyId, $connectionRef, $resourceType, $shopRef, self::TRANSIENT_STREAK_ALERT + 1) as $recent) {
            if (SyncJobStatus::FAILED !== $recent->getStatus() || !str_starts_with((string) $recent->getLastError(), ConnectorTransientException::class)) {
                break;
            }
            ++$streak;
        }

        if (self::TRANSIENT_STREAK_ALERT !== $streak) {
            return;
        }

        $this->logger->error('Marketplace API keeps failing for a sync resource; data is not being loaded.', [
            'companyId' => $companyId,
            'connectionRef' => $connectionRef,
            'resourceType' => $resourceType,
            'consecutiveFailedJobs' => $streak,
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
