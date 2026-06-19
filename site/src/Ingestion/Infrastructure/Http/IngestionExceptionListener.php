<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Http;

use App\Ingestion\Exception\IngestionApiExceptionInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class IngestionExceptionListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 0],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/ingestion/')) {
            return;
        }

        $exception = $event->getThrowable();
        if (!$exception instanceof IngestionApiExceptionInterface) {
            return;
        }

        $event->setResponse(new JsonResponse([
            'error' => [
                'code' => $exception->errorCode(),
                'message' => $exception->publicMessage(),
            ],
        ], Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
