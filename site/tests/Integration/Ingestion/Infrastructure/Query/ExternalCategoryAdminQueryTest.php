<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Infrastructure\Query;

use App\Ingestion\Application\Source\Ozon\OzonAccrualCategoryTaxonomyResolver;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Entity\ExternalCategory;
use App\Ingestion\Entity\ExternalCategoryMapping;
use App\Ingestion\Enum\ExternalCategoryMappingStatus;
use App\Ingestion\Enum\ExternalCategoryStatus;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionType;
use App\Ingestion\Infrastructure\Query\ExternalCategoryAdminQuery;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class ExternalCategoryAdminQueryTest extends IntegrationTestCase
{
    public function testLatestCategoriesPrioritizesNewCategoriesBeforeLimit(): void
    {
        for ($index = 0; $index < 3; ++$index) {
            $category = new ExternalCategory(
                source: IngestSource::OZON,
                resourceType: OzonResourceType::ACCRUAL_BY_DAY,
                scope: OzonAccrualCategoryTaxonomyResolver::SCOPE_ANY,
                normalizedKey: sprintf('type:mapped-%d', $index),
                externalTypeId: sprintf('mapped-%d', $index),
                status: ExternalCategoryStatus::MAPPED,
                seenAt: new \DateTimeImmutable(sprintf('2026-06-25 12:0%d:00', $index)),
            );
            $this->em->persist($category);
            $this->em->persist(new ExternalCategoryMapping(
                externalCategory: $category,
                canonicalCode: sprintf('mapped_%d', $index),
                canonicalLabel: sprintf('Mapped %d', $index),
                canonicalGroup: 'Mapped',
                transactionType: TransactionType::FEE,
                sortOrder: 100 + $index,
                status: ExternalCategoryMappingStatus::ACTIVE,
            ));
        }

        $newCategory = new ExternalCategory(
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            scope: OzonAccrualCategoryTaxonomyResolver::SCOPE_NON_ITEM,
            normalizedKey: 'type:new-1',
            externalTypeId: 'new-1',
            status: ExternalCategoryStatus::NEW,
            seenAt: new \DateTimeImmutable('2026-06-24 12:00:00'),
        );
        $this->em->persist($newCategory);
        $this->em->flush();

        /** @var ExternalCategoryAdminQuery $query */
        $query = self::getContainer()->get(ExternalCategoryAdminQuery::class);
        $rows = $query->latestCategories(2);

        self::assertCount(2, $rows);
        self::assertSame($newCategory->getId(), $rows[0]['id']);
        self::assertSame('new', $rows[0]['status']);
    }
}
