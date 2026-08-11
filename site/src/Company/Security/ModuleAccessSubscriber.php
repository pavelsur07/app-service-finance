<?php

declare(strict_types=1);

namespace App\Company\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Read-гейт модулей на уровне контроллера. Fail-closed:
 * не классифицированный контроллер без #[PublicAccess] получает 403.
 */
final class ModuleAccessSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ModuleAccessMap $map,
        private readonly ModuleAccessResolver $resolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onKernelController',
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        // Гейтятся только main-request'ы: sub-request'ы (ESI, render(controller()))
        // модульным гейтом не покрыты — при появлении таких конструкций пересмотреть.
        if (!$event->isMainRequest()) {
            return;
        }

        [$className, $methodName] = $this->resolveController($event);
        if (null === $className) {
            // Closure и прочие не-классовые контроллеры — вне модульного гейта.
            return;
        }

        if (!str_starts_with($className, 'App\\')) {
            // Vendor-контроллеры (profiler и т.п.) гейтятся своими firewall-правилами.
            return;
        }

        if ($this->hasPublicAccess($className, $methodName)) {
            return;
        }

        if ($this->map->isExempt($className)) {
            return;
        }

        $module = $this->map->resolve($className);
        if (null === $module) {
            $this->logger->warning('Module access: controller is not classified, denying (fail-closed).', [
                'controller' => $className,
            ]);

            throw new AccessDeniedException(sprintf('Controller %s is not covered by module access map.', $className));
        }

        if (!$this->resolver->allows($module, AccessLevel::READ)) {
            throw new AccessDeniedException(sprintf('No "%s" module access.', $module->value));
        }
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function resolveController(ControllerEvent $event): array
    {
        $controller = $event->getController();

        if (\is_array($controller)) {
            return [\is_object($controller[0]) ? $controller[0]::class : (string) $controller[0], (string) $controller[1]];
        }

        if (\is_object($controller) && !$controller instanceof \Closure) {
            return [$controller::class, '__invoke'];
        }

        if (\is_string($controller) && str_contains($controller, '::')) {
            [$class, $method] = explode('::', $controller, 2);

            return [$class, $method];
        }

        return [null, null];
    }

    private function hasPublicAccess(string $className, ?string $methodName): bool
    {
        $reflection = new \ReflectionClass($className);

        if ([] !== $reflection->getAttributes(PublicAccess::class)) {
            return true;
        }

        if (null !== $methodName && $reflection->hasMethod($methodName)) {
            return [] !== $reflection->getMethod($methodName)->getAttributes(PublicAccess::class);
        }

        return false;
    }
}
