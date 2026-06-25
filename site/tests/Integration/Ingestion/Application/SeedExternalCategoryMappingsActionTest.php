<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application;

use App\Ingestion\Application\Action\SeedExternalCategoryMappingsAction;
use App\Ingestion\Application\Action\UpdateExternalCategoryMappingAction;
use App\Ingestion\Application\Source\Ozon\OzonAccrualCategoryTaxonomyResolver;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Entity\ExternalCategoryMapping;
use App\Ingestion\Enum\ExternalCategoryMappingStatus;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionType;
use App\Ingestion\Repository\ExternalCategoryMappingRepository;
use App\Ingestion\Repository\ExternalCategoryRepository;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class SeedExternalCategoryMappingsActionTest extends IntegrationTestCase
{
    public function testSeedsDefaultOzonMappingsIdempotently(): void
    {
        /** @var SeedExternalCategoryMappingsAction $action */
        $action = self::getContainer()->get(SeedExternalCategoryMappingsAction::class);

        $first = $action(IngestSource::OZON);
        $second = $action(IngestSource::OZON);

        self::assertGreaterThan(0, $first['categoriesCreated']);
        self::assertSame($first['mappingsCreated'], $second['mappingsExisting']);
        self::assertSame(0, $second['mappingsCreated']);

        /** @var ExternalCategoryMappingRepository $mappingRepository */
        $mappingRepository = self::getContainer()->get(ExternalCategoryMappingRepository::class);
        $normalizedKey = OzonAccrualCategoryTaxonomyResolver::nameKey('Acquiring');
        self::assertNotNull($normalizedKey);

        $mapping = $mappingRepository->findActiveByIdentity(
            IngestSource::OZON,
            OzonResourceType::ACCRUAL_BY_DAY,
            OzonAccrualCategoryTaxonomyResolver::SCOPE_ANY,
            $normalizedKey,
        );

        self::assertInstanceOf(ExternalCategoryMapping::class, $mapping);
        self::assertSame('ozon_acquiring', $mapping->getCanonicalCode());
        self::assertSame('Эквайринг', $mapping->getCanonicalLabel());
        self::assertSame('Услуги партнёров', $mapping->getCanonicalGroup());
    }

    public function testAdminCanUpdateSeededMapping(): void
    {
        /** @var SeedExternalCategoryMappingsAction $seedAction */
        $seedAction = self::getContainer()->get(SeedExternalCategoryMappingsAction::class);
        $seedAction(IngestSource::OZON);

        $normalizedKey = OzonAccrualCategoryTaxonomyResolver::nameKey('Acquiring');
        self::assertNotNull($normalizedKey);

        /** @var ExternalCategoryRepository $categoryRepository */
        $categoryRepository = self::getContainer()->get(ExternalCategoryRepository::class);
        $category = $categoryRepository->findByIdentity(
            IngestSource::OZON,
            OzonResourceType::ACCRUAL_BY_DAY,
            OzonAccrualCategoryTaxonomyResolver::SCOPE_ANY,
            $normalizedKey,
        );
        self::assertNotNull($category);

        /** @var UpdateExternalCategoryMappingAction $updateAction */
        $updateAction = self::getContainer()->get(UpdateExternalCategoryMappingAction::class);
        $updateAction(
            categoryId: $category->getId(),
            canonicalCode: 'ozon_custom_acquiring',
            canonicalLabel: 'Эквайринг Ozon custom',
            canonicalGroup: 'Услуги партнёров custom',
            transactionType: TransactionType::FEE,
            sortOrder: 777,
            status: ExternalCategoryMappingStatus::ACTIVE,
            known: true,
        );

        /** @var ExternalCategoryMappingRepository $mappingRepository */
        $mappingRepository = self::getContainer()->get(ExternalCategoryMappingRepository::class);
        $mapping = $mappingRepository->findActiveByIdentity(
            IngestSource::OZON,
            OzonResourceType::ACCRUAL_BY_DAY,
            OzonAccrualCategoryTaxonomyResolver::SCOPE_ANY,
            $normalizedKey,
        );

        self::assertInstanceOf(ExternalCategoryMapping::class, $mapping);
        self::assertSame('ozon_custom_acquiring', $mapping->getCanonicalCode());
        self::assertSame('Эквайринг Ozon custom', $mapping->getCanonicalLabel());
        self::assertSame('Услуги партнёров custom', $mapping->getCanonicalGroup());
        self::assertSame(TransactionType::FEE, $mapping->getTransactionType());
        self::assertSame(777, $mapping->getSortOrder());
    }
}
