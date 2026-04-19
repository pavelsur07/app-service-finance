<?php

declare(strict_types=1);

namespace App\MarketplaceAds\EventSubscriber;

use App\MarketplaceAds\Message\FetchOzonAdStatisticsMessage;
use App\MarketplaceAds\Repository\AdLoadJobRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * Финализирует AdLoadJob как FAILED, когда FetchOzonAdStatisticsMessage
 * окончательно исчерпал все Messenger retries.
 *
 * Без этого job'ы зависают в RUNNING навсегда: FetchOzonAdStatisticsHandler
 * помечает job FAILED только на permanent-ошибках (OzonPermanentApiException,
 * InvalidArgumentException). Transient-ошибки (502, таймауты, сетевые падения)
 * после исчерпания retries уходят в failure transport без обновления статуса.
 *
 * Идемпотентен: markFailed на уровне SQL имеет guard `status IN (pending, running)`,
 * повторный вызов на уже терминальном задании — no-op.
 */
final class AdLoadJobFailureSubscriber implements EventSubscriberInterface
{
    private const REASON_MAX_LENGTH = 1000;

    public function __construct(
        private readonly AdLoadJobRepositoryInterface $adLoadJobRepository,
        #[Autowire(service: 'monolog.logger.marketplace_ads')]
        private readonly LoggerInterface $marketplaceAdsLogger,
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
        if (!$message instanceof FetchOzonAdStatisticsMessage) {
            return;
        }

        $throwable = $event->getThrowable();
        $rootCause = $throwable instanceof HandlerFailedException && null !== $throwable->getPrevious()
            ? $throwable->getPrevious()
            : $throwable;

        $reason = $rootCause::class.': '.$rootCause->getMessage();
        if (mb_strlen($reason, 'UTF-8') > self::REASON_MAX_LENGTH) {
            $reason = mb_substr($reason, 0, self::REASON_MAX_LENGTH, 'UTF-8');
        }

        $this->adLoadJobRepository->markFailed($message->jobId, $message->companyId, $reason);

        $this->marketplaceAdsLogger->warning('AdLoadJob marked as failed after retries exhausted', [
            'job_id' => $message->jobId,
            'company_id' => $message->companyId,
            'message_type' => $message::class,
            'error_class' => $rootCause::class,
            'error_message' => $rootCause->getMessage(),
        ]);
    }
}
