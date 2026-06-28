<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Sentry;

use App\Shared\Audit\AuditContextProvider;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\UserDataBag;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Обогащает Sentry-scope контекстом HTTP-запроса: user id и company_id.
 *
 * Источник — {@see AuditContextProvider} (уже безопасно гардит консоль/отсутствие
 * запроса и ловит NotFoundHttpException для активной компании). Даёт триаж по
 * пользователю/компании для ошибок, залогированных в рамках запроса.
 */
final class SentryRequestScopeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly AuditContextProvider $auditContext,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 0],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $userId = $this->auditContext->getActorUserId();
        $companyId = $this->auditContext->getCompanyId();

        $this->hub->configureScope(static function (Scope $scope) use ($userId, $companyId): void {
            if (null !== $userId) {
                $scope->setUser(UserDataBag::createFromUserIdentifier($userId));
            }

            if (null !== $companyId) {
                $scope->setTag('company_id', $companyId);
            }
        });
    }
}
