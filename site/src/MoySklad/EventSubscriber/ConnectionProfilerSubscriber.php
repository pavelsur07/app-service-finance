<?php

declare(strict_types=1);

namespace App\MoySklad\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Profiler\Profiler;

/** Prevent the request/form profiler from retaining submitted connection secrets. */
final readonly class ConnectionProfilerSubscriber implements EventSubscriberInterface
{
    public function __construct(private ?Profiler $profiler = null)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 1024]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest() && str_starts_with($event->getRequest()->getPathInfo(), '/moy-sklad')) {
            $this->profiler?->disable();
        }
    }
}
