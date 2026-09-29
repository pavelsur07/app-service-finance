<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Controller;

use App\Marketplace\Controller\MarketplaceController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;

final class MarketplaceProcessCostsRouteTest extends TestCase
{
    public function testProcessCostsRouteExistsForRawDocumentsListAction(): void
    {
        $method = new \ReflectionMethod(MarketplaceController::class, 'processCosts');
        $routes = $method->getAttributes(Route::class, \ReflectionAttribute::IS_INSTANCEOF);

        self::assertCount(1, $routes);

        /** @var Route $route */
        $route = $routes[0]->newInstance();

        self::assertSame('/raw/{id}/process-costs', $route->path);
        self::assertSame('marketplace_raw_process_costs', $route->name);
        self::assertSame(['POST'], $route->methods);
    }
}
