<?php

declare(strict_types=1);

namespace App\Budget\Infrastructure\Http;

use App\Budget\Exception\BudgetApprovalForbiddenException;
use App\Budget\Exception\BudgetFillOverwriteUnconfirmedException;
use App\Budget\Exception\BudgetVersionNotEditableException;
use App\Budget\Exception\BudgetVersionNotFoundException;
use App\Budget\Exception\BudgetYearAlreadyApprovedException;
use App\Budget\Exception\InvalidBudgetInputException;
use App\Budget\Exception\InvalidBudgetPairException;
use App\Budget\Exception\InvalidBudgetVersionTransitionException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Доменные исключения бюджета под `/finance/budget`: API варианта (`/versions/{id}/grid|cells|fill|plan-fact`; страница `/finance/budget/plan-fact` — HTML) и запросы с
 * `Accept: application/json` — `{error: {code, message}}` со статусом;
 * HTML — «вариант не найден» как 404 (остальное HTML-контроллеры показывают flash сами).
 */
final readonly class BudgetExceptionListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', -1]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if ('/finance/budget' !== $path && !str_starts_with($path, '/finance/budget/')) {
            return;
        }
        $exception = $event->getThrowable();
        [$status, $code] = match (true) {
            $exception instanceof BudgetVersionNotFoundException => [404, 'budget_version_not_found'],
            $exception instanceof InvalidBudgetInputException => [422, 'budget_invalid_input'],
            $exception instanceof InvalidBudgetPairException => [422, 'budget_invalid_pair'],
            $exception instanceof BudgetVersionNotEditableException => [409, 'budget_version_not_editable'],
            $exception instanceof BudgetFillOverwriteUnconfirmedException => [409, 'budget_fill_overwrite_unconfirmed'],
            $exception instanceof BudgetYearAlreadyApprovedException => [409, 'budget_year_already_approved'],
            $exception instanceof InvalidBudgetVersionTransitionException => [409, 'budget_invalid_transition'],
            $exception instanceof BudgetApprovalForbiddenException => [403, 'budget_approval_forbidden'],
            default => [null, null],
        };
        if (null === $status) {
            return;
        }

        // API сетки отвечает JSON всегда, остальное — по заголовку Accept.
        $api = 1 === preg_match('#^/finance/budget/versions/[^/]+/(grid|cells|fill|plan-fact)$#', $path);
        if ($api || str_contains($request->headers->get('Accept', ''), 'application/json')) {
            $event->setResponse(new JsonResponse(['error' => ['code' => $code, 'message' => $exception->getMessage()]], $status));

            return;
        }
        if (404 === $status) {
            $event->setThrowable(new NotFoundHttpException($exception->getMessage(), $exception));
        }
    }
}
