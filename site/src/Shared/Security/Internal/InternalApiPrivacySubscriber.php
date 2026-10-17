<?php

declare(strict_types=1);

namespace App\Shared\Security\Internal;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Profiler\Profiler;

/**
 * Profiler (dev) сохраняет заголовки запроса на диск, включая `Authorization`
 * с внутренним токеном, и ответы `/internal/*` с ключами маркетплейсов.
 * Для `/internal/*` профиль не пишется — ни при успехе, ни при отказе.
 */
final readonly class InternalApiPrivacySubscriber implements EventSubscriberInterface
{
    public function __construct(private ?Profiler $profiler = null)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Раньше firewall (priority 8), чтобы отказ авторизации тоже не попал в профиль.
        return [KernelEvents::REQUEST => ['onRequest', 256]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (str_starts_with($event->getRequest()->getPathInfo(), '/internal/')) {
            $this->profiler?->disable();
        }
    }
}
