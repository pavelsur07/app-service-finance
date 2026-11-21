<?php

declare(strict_types=1);

namespace App\Api\Infrastructure\Http;

use App\Shared\Infrastructure\Http\McpRequestPath;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Profiler\Profiler;

/** Keep API and OAuth credentials out of the profiler. */
final readonly class ApiPrivacySubscriber implements EventSubscriberInterface
{
    public function __construct(private ?Profiler $profiler = null)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 256], KernelEvents::RESPONSE => ['onResponse', -128]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if ($this->protects($event->getRequest()->getPathInfo())) {
            $this->profiler?->disable();
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if ($this->protects($event->getRequest()->getPathInfo())) {
            $event->getResponse()->headers->set('Cache-Control', 'no-store');
            $event->getResponse()->headers->set('Referrer-Policy', 'no-referrer');
        }
    }

    private function protects(string $path): bool
    {
        return str_starts_with($path, '/api/external/v1/')
            || '/settings/api' === $path
            || str_starts_with($path, '/settings/api/')
            || McpRequestPath::containsSensitiveData($path);
    }
}
