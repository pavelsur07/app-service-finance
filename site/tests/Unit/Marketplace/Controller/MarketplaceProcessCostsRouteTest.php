<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Controller;

use App\Marketplace\Controller\MarketplaceController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Annotation\Route;

final class MarketplaceProcessCostsRouteTest extends TestCase
{
    public function testProcessCostsRouteExistsForRawDocumentsListAction(): void
    {
        $method = new \ReflectionMethod(MarketplaceController::class, 'processCosts');
        $routes = $method->getAttributes(Route::class);

        self::assertCount(1, $routes);

        /** @var Route $route */
        $route = $routes[0]->newInstance();

        self::assertSame('/raw/{id}/process-costs', $route->getPath());
        self::assertSame('marketplace_raw_process_costs', $route->getName());
        self::assertSame(['POST'], $route->getMethods());
    }
}
