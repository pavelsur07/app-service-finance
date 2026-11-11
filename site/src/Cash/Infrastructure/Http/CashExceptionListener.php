<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Http;

use App\Cash\Exception\CashApiExceptionInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class CashExceptionListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onKernelException', 0]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/') && 'cash_transaction_create_from' !== $event->getRequest()->attributes->get('_route')) {
            return;
        }

        $exception = $event->getThrowable();
        if (!$exception instanceof CashApiExceptionInterface) {
            return;
        }

        $event->setResponse(new JsonResponse([
            'error' => [
                'code' => $exception->errorCode(),
                'message' => $exception->publicMessage(),
            ],
        ], $exception->httpStatus()));
    }
}
