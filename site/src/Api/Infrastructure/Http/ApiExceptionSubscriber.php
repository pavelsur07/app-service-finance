<?php

declare(strict_types=1);

namespace App\Api\Infrastructure\Http;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

final readonly class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 64]];
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/external/v1/')) {
            return;
        }
        $error = $event->getThrowable();
        $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : ($error instanceof AuthenticationException ? 401 : ($error instanceof AccessDeniedException ? 403 : 500));
        $headers = $error instanceof HttpExceptionInterface ? $error->getHeaders() : [];
        if ($status >= 500) {
            $this->logger->error('External API request failed.', ['exception' => $error, 'status' => $status]);
        }
        $detail = $status < 500 ? $error->getMessage() : 'An internal error occurred.';
        $event->setResponse(ApiProblem::response($status, $detail, $headers));
    }
}
