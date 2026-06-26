<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Infrastructure;

use App\Tests\Support\Kernel\IntegrationTestCase;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\RouterInterface;

final class ExternalCategoriesAdminRouteTest extends IntegrationTestCase
{
    public function testExternalCategoriesAdminScreenUsesIngestionRoute(): void
    {
        /** @var RouterInterface $router */
        $router = self::getContainer()->get(RouterInterface::class);

        self::assertSame(
            'admin_ingestion_external_categories_index',
            $router->match('/admin/ingestion/external-categories')['_route'],
        );

        $this->expectException(ResourceNotFoundException::class);
        $router->match('/admin/marketplace/category-taxonomy');
    }
}
