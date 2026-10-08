<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Performance;

use App\Shared\Infrastructure\Messenger\MessageCompanyId;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

/**
 * Область замера на каждое сообщение воркера: ожидание в очереди и время обработчика.
 *
 * Только читает штампы конверта — сообщения, их классы и сериализация не меняются,
 * поэтому совместимо со старыми сообщениями в Redis при rolling deploy.
 *
 * Время постановки берётся из id записи Redis Stream (`<unix ms>-<seq>`, XADD '*'):
 * это момент, когда сообщение стало доступно воркеру. Для отложенных (DelayStamp,
 * повтор) — момент переноса из отложенного набора в поток, то есть lag без задержки.
 * Часы Redis и воркера на одном хосте. Для failed (Doctrine, целочисленный id) lag
 * недоступен — событие пишется с `lag_source=unavailable`.
 */
final class MessengerPerformanceSubscriber implements EventSubscriberInterface
{
    private const REDIS_STREAM_ID = '/^(\d{10,16})-\d+$/';

    public function __construct(private readonly PerformanceRecorder $recorder)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageReceivedEvent::class => 'onReceived',
            WorkerMessageHandledEvent::class => 'onHandled',
            // Ниже SendFailedMessageForRetryListener (priority 100): willRetry() уже выставлен.
            WorkerMessageFailedEvent::class => ['onFailed', -100],
        ];
    }

    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        if (!$this->recorder->isEnabled()) {
            return;
        }

        // Диагностика не должна ронять воркер: исключение из Received-слушателя
        // прервало бы обработку сообщения.
        try {
            $envelope = $event->getEnvelope();
            $message = $envelope->getMessage();
            $messageId = $envelope->last(TransportMessageIdStamp::class)?->getId();
            $trace = \is_string($messageId) || \is_int($messageId) ? (string) $messageId : null;

            $this->recorder->beginScope(
                job: (new \ReflectionClass($message))->getShortName(),
                provider: self::providerOf($message),
                companyId: MessageCompanyId::of($message),
                transport: $event->getReceiverName(),
                trace: $trace,
                retryCount: $envelope->last(RedeliveryStamp::class)?->getRetryCount() ?? 0,
            );

            if (null !== $trace && 1 === preg_match(self::REDIS_STREAM_ID, $trace, $m)) {
                $this->recorder->recordQueueWait(microtime(true) * 1000 - (float) $m[1], 'redis_stream_id');
            } else {
                $this->recorder->recordQueueWait(null, 'unavailable');
            }
        } catch (\Throwable $e) {
            $this->recorder->reportFailure($e);
        }
    }

    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        $this->recorder->endScope(PerformanceOutcome::Ok);
    }

    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        $this->recorder->endScope($event->willRetry() ? PerformanceOutcome::Retry : PerformanceOutcome::Error);
    }

    /**
     * Провайдер из публичного поля `marketplace` (CloseMonthStage и др.), иначе по имени
     * класса. Общие сообщения (ProcessDayReport, step) уточняют его после загрузки
     * документа через {@see PerformanceRecorder::setProvider()}.
     */
    public static function providerOf(object $message): string
    {
        $marketplace = isset($message->marketplace) && \is_string($message->marketplace) ? $message->marketplace : null;
        if ('ozon' === $marketplace) {
            return 'ozon';
        }
        if ('wildberries' === $marketplace) {
            return 'wb';
        }

        $short = (new \ReflectionClass($message))->getShortName();

        return match (true) {
            str_contains($short, 'Ozon') => 'ozon',
            str_contains($short, 'Wb'), str_contains($short, 'Wildberries') => 'wb',
            default => 'none',
        };
    }
}
