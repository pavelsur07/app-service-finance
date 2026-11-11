<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Security\Spa\SpaContext;
use App\Shared\Security\Spa\SpaHttpException;
use App\Shared\Security\Spa\SpaProblem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class SpaHttpSubscriber implements EventSubscriberInterface
{
    public function __construct(private SpaContext $context)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 40], KernelEvents::EXCEPTION => ['onException', -64], KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if (null === $this->context->hostRealm($request) && !$this->context->isApiPath($request)) {
            return;
        }
        if (null === $this->context->realm($request)) {
            $event->setResponse(SpaProblem::response(404, 'not_found', 'Не найдено.'));

            return;
        }
        $request->setRequestFormat('json');
        if ($request->isMethodSafe()) {
            return;
        }
        $origin = $request->headers->get('origin');
        if (null === $origin) {
            $referer = $request->headers->get('referer');
            $parts = null !== $referer ? parse_url($referer) : false;
            $origin = \is_array($parts) && isset($parts['scheme'], $parts['host'])
                ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '') : null;
        }
        if ($origin !== $request->getSchemeAndHttpHost()) {
            throw new SpaHttpException(403, 'origin_invalid', 'Недопустимый источник запроса.');
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if ($this->context->isApiPath($event->getRequest()) || null !== $this->context->hostRealm($event->getRequest())) {
            $event->getResponse()->headers->set('Cache-Control', 'no-store');
        }
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$this->context->isApiPath($event->getRequest()) && null === $this->context->hostRealm($event->getRequest())) {
            return;
        }
        $error = $event->getThrowable();
        if ($error instanceof SpaHttpException) {
            $event->setResponse(SpaProblem::response($error->getStatusCode(), $error->errorCode, $error->getMessage()));

            return;
        }
        $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : 500;
        [$code, $message] = match ($status) {
            400 => ['invalid_json', 'Некорректный JSON.'],
            401 => ['unauthenticated', 'Войдите в приложение.'],
            403 => ['access_denied', 'Доступ запрещён.'],
            404 => ['not_found', 'Не найдено.'],
            405 => ['method_not_allowed', 'Недопустимый метод запроса.'],
            415 => ['unsupported_media_type', 'Требуется JSON.'],
            422 => ['validation_failed', 'Проверьте поля запроса.'],
            default => ['internal_error', 'Не удалось выполнить запрос.'],
        };
        $response = SpaProblem::response($status, $code, $message);
        if ($error instanceof HttpExceptionInterface) {
            $response->headers->add($error->getHeaders());
        }
        $event->setResponse($response);
    }
}
