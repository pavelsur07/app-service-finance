<?php

declare(strict_types=1);

namespace App\Settlement\Infrastructure\Http;

use App\Settlement\Exception\SettlementSourceInUseException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class SettlementSourceExceptionListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 256]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof SettlementSourceInUseException) {
            return;
        }
        $request = $event->getRequest();
        if (str_starts_with($request->getPathInfo(), '/api/') || str_contains($request->headers->get('Accept', ''), 'application/json')) {
            $event->setResponse(new JsonResponse(['error' => ['code' => 'settlement_source_in_use', 'message' => $exception->getMessage()]], 422));

            return;
        }
    }
}
