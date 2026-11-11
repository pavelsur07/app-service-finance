<?php

declare(strict_types=1);

namespace App\Shared\Security\Spa;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final readonly class SpaLogoutSubscriber implements EventSubscriberInterface
{
    public function __construct(private SpaContext $context, private SpaCsrf $csrf)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Validate before Symfony invalidates the session at priority 0.
        return [LogoutEvent::class => ['onLogout', 128]];
    }

    public function onLogout(LogoutEvent $event): void
    {
        $request = $event->getRequest();
        if (null === $this->context->realm($request)) {
            return;
        }
        if (null === $event->getToken()?->getUser()) {
            throw new SpaHttpException(401, 'unauthenticated', 'Войдите в приложение.');
        }
        $this->csrf->validate($request, 'logout');
        $response = new Response(null, 204);
        $response->headers->clearCookie($request->getSession()->getName(), '/', null, $request->isSecure(), true, 'lax');
        $event->setResponse($response);
    }
}
