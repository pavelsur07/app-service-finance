<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Controller\Internal;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Контроллеры `*\Controller\Internal\` сняты с модульного гейта
 * (`ModuleAccessMap::EXEMPT_PREFIXES`), их закрывает только firewall
 * `internal_api` по пути `^/internal/`. Маршрут такого контроллера вне
 * `/internal/` остался бы без обеих защит — инвариант держит их вместе.
 */
final class InternalRoutesInvariantTest extends KernelTestCase
{
    public function testInternalControllersServeOnlyInternalPathsAndViceVersa(): void
    {
        $router = static::getContainer()->get(RouterInterface::class);
        $violations = [];
        $internalRoutes = 0;

        foreach ($router->getRouteCollection() as $name => $route) {
            $controller = (string) $route->getDefault('_controller');
            $isInternalController = str_contains($controller, '\\Controller\\Internal\\');
            $isInternalPath = str_starts_with($route->getPath(), '/internal/');

            if ($isInternalController !== $isInternalPath) {
                $violations[] = sprintf('%s: %s → %s', $name, $route->getPath(), $controller);
            }
            if ($isInternalPath) {
                ++$internalRoutes;
            }
        }

        self::assertSame([], $violations);
        self::assertGreaterThan(0, $internalRoutes);
    }
}
