<?php

declare(strict_types=1);

namespace App\Api\Security;

use Ramsey\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final readonly class ApiAccessSubscriber implements EventSubscriberInterface
{
    public function __construct(private TokenStorageInterface $tokens)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => ['onController', 32]];
    }

    public function onController(ControllerEvent $event): void
    {
        $controller = $event->getController();
        $object = \is_array($controller) ? $controller[0] : $controller;
        $externalClass = \is_object($object) && str_starts_with($object::class, 'App\\Api\\Controller\\External\\');
        $externalPath = str_starts_with($event->getRequest()->getPathInfo(), '/api/external/v1/');
        if (!$externalPath && !$externalClass) {
            return;
        }
        $principal = $this->tokens->getToken()?->getUser();
        if (!$externalPath || !$principal instanceof ApiPrincipal || !\is_object($object)) {
            throw new AccessDeniedHttpException('External API authentication required.');
        }
        $method = new \ReflectionMethod($object, \is_array($controller) ? $controller[1] : '__invoke');
        $attributes = $method->getAttributes(ApiAccess::class);
        if ([] === $attributes || ApiAccess::CONNECTION !== $attributes[0]->newInstance()->scope) {
            throw new AccessDeniedHttpException('Endpoint permission is not available.');
        }
        $event->getRequest()->attributes->set(ApiRequestContext::class, new ApiRequestContext(
            $principal->companyId, $principal->keyId, Uuid::uuid7()->toString(),
        ));
    }
}
