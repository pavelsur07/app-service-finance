<?php

declare(strict_types=1);

namespace App\Api\Infrastructure\Http;

use App\Api\Exception\ApiKeyNotFoundException;
use App\Api\Exception\ApiKeyOwnerRequiredException;
use App\Api\Exception\InvalidApiKeyNameException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

final readonly class ApiSettingsExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(private Environment $twig)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 64]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();
        if ('/settings/api' !== $path && !str_starts_with($path, '/settings/api/')) {
            return;
        }
        $error = $event->getThrowable();
        $status = match (true) {
            $error instanceof ApiKeyOwnerRequiredException => 403,
            $error instanceof ApiKeyNotFoundException => 404,
            $error instanceof InvalidApiKeyNameException, $error instanceof UnprocessableEntityHttpException => 422,
            default => null,
        };
        if (null !== $status) {
            $event->setResponse(new Response($this->twig->render('api/settings/error.html.twig', ['message' => $error->getMessage()]), $status));
        }
    }
}
