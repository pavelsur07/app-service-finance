<?php

declare(strict_types=1);

namespace App\Balance\Infrastructure\Http;

use App\Balance\Exception\BalanceLedgerException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

final readonly class BalanceExceptionListener implements EventSubscriberInterface
{
    public function __construct(private Environment $twig)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', -1]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();
        if ('/balance' !== $path && !str_starts_with($path, '/balance/')) {
            return;
        }
        $exception = $event->getThrowable();
        if (!$exception instanceof BalanceLedgerException && !$exception instanceof UniqueConstraintViolationException) {
            return;
        }
        $status = $exception instanceof BalanceLedgerException ? $exception->statusCode : 422;
        $message = $exception->getMessage();
        if ($exception instanceof UniqueConstraintViolationException) {
            $status = 409;
            $message = 'Запись с таким кодом или ключом запроса уже существует. Обновите страницу.';
        }
        $code = match ($status) {
            404 => 'balance_not_found',
            409 => 'balance_conflict',
            default => 'balance_validation_failed',
        };
        if (str_contains($event->getRequest()->headers->get('Accept', ''), 'application/json')) {
            $event->setResponse(new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status));

            return;
        }
        $event->setResponse(new Response($this->twig->render('balance_error.html.twig', ['message' => $message]), $status));
    }
}
