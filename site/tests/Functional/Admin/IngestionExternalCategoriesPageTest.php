<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Ingestion\Entity\ExternalCategory;
use App\Ingestion\Enum\IngestSource;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class IngestionExternalCategoriesPageTest extends WebTestCaseBase
{
    public function testMappingFormIsRenderedInsideRowDialog(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $admin = UserBuilder::aUser()
            ->withEmail('admin@example.test')
            ->withRoles(['ROLE_ADMIN'])
            ->build();

        $category = new ExternalCategory(
            source: IngestSource::OZON,
            resourceType: 'accrual',
            scope: 'operation_type',
            normalizedKey: 'marketplaceservicedelivery',
            externalCode: 'MarketplaceServiceDelivery',
            externalName: 'Доставка покупателю',
        );

        $em = $this->em();
        $em->persist($admin);
        $em->persist($category);
        $em->flush();

        $client->loginUser($admin, 'admin');
        $crawler = $client->request('GET', '/admin/ingestion/external-categories');

        self::assertResponseIsSuccessful();

        // ID начинается с цифры — только атрибутный селектор, не #id.
        $dialog = sprintf('dialog[id="mapping-dialog-%s"]', $category->getId());
        self::assertCount(1, $crawler->filter(sprintf('button[data-admin-dialog-open="mapping-dialog-%s"]', $category->getId())));
        self::assertCount(1, $crawler->filter($dialog.' form input[name="canonical_code"]'));

        // Форма маппинга живёт только в модалке — в ячейке таблицы её быть не должно.
        self::assertCount(0, $crawler->filter('table.t-table td form'));
    }
}
