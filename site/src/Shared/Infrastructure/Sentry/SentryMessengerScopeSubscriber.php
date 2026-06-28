<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Sentry;

use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/**
 * Обогащает Sentry-scope контекстом обрабатываемого сообщения (в воркерах).
 *
 * На каждое принятое воркером сообщение выставляет теги `messenger.message`
 * (класс) и `company_id` (если сообщение его несёт). Теги перезаписываются на
 * следующем сообщении — баланса push/pop не требуется (воркер обрабатывает
 * сообщения последовательно). Это даёт триаж по компании/типу сообщения для
 * ошибок, залогированных во время обработки.
 *
 * Реализовано через WorkerMessage-событие (автоконфигурируемый подписчик),
 * а не Messenger-middleware — без правки messenger.yaml.
 */
final class SentryMessengerScopeSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly HubInterface $hub)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageReceivedEvent::class => 'onMessageReceived',
        ];
    }

    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        $companyId = $this->companyId($message);

        $this->hub->configureScope(static function (Scope $scope) use ($message, $companyId): void {
            $scope->setTag('messenger.message', $message::class);

            if (null !== $companyId) {
                $scope->setTag('company_id', $companyId);
            } else {
                $scope->removeTag('company_id');
            }
        });
    }

    private function companyId(object $message): ?string
    {
        if (method_exists($message, 'getCompanyId')) {
            $value = $message->getCompanyId();

            return \is_string($value) ? $value : null;
        }

        if (property_exists($message, 'companyId')) {
            $value = $message->companyId;

            return \is_string($value) ? $value : null;
        }

        return null;
    }
}
