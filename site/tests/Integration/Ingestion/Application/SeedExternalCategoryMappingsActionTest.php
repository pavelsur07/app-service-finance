<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application;

use App\Ingestion\Application\Action\SeedExternalCategoryMappingsAction;
use App\Ingestion\Application\Action\UpdateExternalCategoryMappingAction;
use App\Ingestion\Application\Source\Ozon\OzonAccrualCategoryTaxonomyResolver;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Entity\ExternalCategory;
use App\Ingestion\Entity\ExternalCategoryCompanyMapping;
use App\Ingestion\Entity\ExternalCategoryMapping;
use App\Ingestion\Enum\ExternalCategoryMappingStatus;
use App\Ingestion\Enum\ExternalCategoryStatus;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionType;
use App\Ingestion\Repository\ExternalCategoryCompanyMappingRepository;
use App\Ingestion\Repository\ExternalCategoryMappingRepository;
use App\Ingestion\Repository\ExternalCategoryRepository;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

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
        $normalizedKey = OzonAccrualCategoryTaxonomyResolver::codeKey('ozon_acquiring');
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

        /** @var ExternalCategoryRepository $categoryRepository */
        $categoryRepository = self::getContainer()->get(ExternalCategoryRepository::class);

        $apiAliasKey = OzonAccrualCategoryTaxonomyResolver::codeKey('Logistic');
        self::assertNotNull($apiAliasKey);
        self::assertNotNull($categoryRepository->findByIdentity(
            IngestSource::OZON,
            OzonResourceType::ACCRUAL_BY_DAY,
            OzonAccrualCategoryTaxonomyResolver::SCOPE_ANY,
            $apiAliasKey,
        ));

        $displayAliasKey = OzonAccrualCategoryTaxonomyResolver::codeKey('Логистика Ozon');
        self::assertNotNull($displayAliasKey);
        self::assertNull($categoryRepository->findByIdentity(
            IngestSource::OZON,
            OzonResourceType::ACCRUAL_BY_DAY,
            OzonAccrualCategoryTaxonomyResolver::SCOPE_ANY,
            $displayAliasKey,
        ));
    }

    public function testSeedsObservedOzonProviderCategories(): void
    {
        /** @var SeedExternalCategoryMappingsAction $action */
        $action = self::getContainer()->get(SeedExternalCategoryMappingsAction::class);
        $action(IngestSource::OZON);

        /** @var ExternalCategoryMappingRepository $mappingRepository */
        $mappingRepository = self::getContainer()->get(ExternalCategoryMappingRepository::class);

        $expectedMappings = [
            'AcceleratedReviewCollection' => ['ozon_accelerated_reviews', 'Ускоренный сбор отзывов', 'Продвижение и реклама', TransactionType::ADVERTISING],
            'BrandCommission' => ['ozon_brand_commission', 'Продвижение бренда', 'Продвижение и реклама', TransactionType::ADVERTISING],
            'Compensation' => ['ozon_compensation', 'Компенсация', 'Компенсации', TransactionType::ADJUSTMENT],
            'DefectFineComplaint' => ['ozon_defect_fine_complaint', 'Штраф за жалобу', 'Другие услуги и штрафы', TransactionType::PENALTY],
            'DefectFineShipmentDelayRate' => ['ozon_defect_fine_shipment_delay', 'Штраф за задержку отгрузки', 'Другие услуги и штрафы', TransactionType::PENALTY],
            'Drop-Off' => ['ozon_drop_off', 'Drop-off', 'Услуги доставки', TransactionType::LOGISTICS],
            'EarlyPayment' => ['ozon_early_payout', 'Досрочная выплата', 'Другие услуги и штрафы', TransactionType::FEE],
            'InternetSiteAdvertising' => ['ozon_site_advertising', 'Реклама на сайте', 'Продвижение и реклама', TransactionType::ADVERTISING],
            'ItemCompensation' => ['ozon_item_compensation', 'Компенсация товара', 'Компенсации', TransactionType::ADJUSTMENT],
            'LabelOriginal' => ['ozon_original_labeling', 'Маркировка оригинальности', 'Другие услуги и штрафы', TransactionType::FEE],
            'Marketing' => ['ozon_marketing', 'Маркетинг', 'Продвижение и реклама', TransactionType::ADVERTISING],
            'PayPerClick' => ['ozon_cpc', 'Оплата за клик', 'Продвижение и реклама', TransactionType::ADVERTISING],
            'Placements' => ['ozon_partner_placement', 'Размещение товара партнёрами', 'Услуги партнёров', TransactionType::STORAGE],
            'PremiumSubscription' => ['ozon_premium_subscription', 'Premium-подписка', 'Другие услуги и штрафы', TransactionType::FEE],
            'Promotion' => ['ozon_promotion', 'Продвижение', 'Продвижение и реклама', TransactionType::ADVERTISING],
            'PushCampaign' => ['ozon_push_campaign', 'Push-кампании', 'Продвижение и реклама', TransactionType::ADVERTISING],
            'StarsMembership' => ['ozon_stars_membership', 'Звёздные товары', 'Продвижение и реклама', TransactionType::ADVERTISING],
            'TemporaryPlacement' => ['ozon_temporary_partner_storage', 'Временное размещение товара партнёрами', 'Услуги партнёров', TransactionType::STORAGE],
        ];

        foreach ($expectedMappings as $providerCategory => [$code, $label, $group, $type]) {
            $normalizedKey = OzonAccrualCategoryTaxonomyResolver::codeKey($providerCategory);
            self::assertNotNull($normalizedKey);

            $mapping = $mappingRepository->findActiveByIdentity(
                IngestSource::OZON,
                OzonResourceType::ACCRUAL_BY_DAY,
                OzonAccrualCategoryTaxonomyResolver::SCOPE_ANY,
                $normalizedKey,
            );

            self::assertInstanceOf(ExternalCategoryMapping::class, $mapping);
            self::assertSame($code, $mapping->getCanonicalCode());
            self::assertSame($label, $mapping->getCanonicalLabel());
            self::assertSame($group, $mapping->getCanonicalGroup());
            self::assertSame($type, $mapping->getTransactionType());
        }
    }

    public function testSeedsMappingForExistingScopedProviderCategory(): void
    {
        $normalizedKey = OzonAccrualCategoryTaxonomyResolver::codeKey('StarsMembership');
        self::assertNotNull($normalizedKey);

        $category = new ExternalCategory(
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            scope: OzonAccrualCategoryTaxonomyResolver::SCOPE_ITEM,
            normalizedKey: $normalizedKey,
            externalTypeId: '74',
            externalCode: 'StarsMembership',
            externalName: 'StarsMembership',
            providerLabel: 'StarsMembership',
            displayLabel: 'StarsMembership',
            status: ExternalCategoryStatus::NEW,
        );
        $this->em->persist($category);
        $this->em->flush();

        /** @var SeedExternalCategoryMappingsAction $action */
        $action = self::getContainer()->get(SeedExternalCategoryMappingsAction::class);
        $stats = $action(IngestSource::OZON);

        self::assertGreaterThan(0, $stats['mappingsCreated']);

        /** @var ExternalCategoryMappingRepository $mappingRepository */
        $mappingRepository = self::getContainer()->get(ExternalCategoryMappingRepository::class);
        $mapping = $mappingRepository->findActiveByIdentity(
            IngestSource::OZON,
            OzonResourceType::ACCRUAL_BY_DAY,
            OzonAccrualCategoryTaxonomyResolver::SCOPE_ITEM,
            $normalizedKey,
        );

        self::assertInstanceOf(ExternalCategoryMapping::class, $mapping);
        self::assertSame('ozon_stars_membership', $mapping->getCanonicalCode());
        self::assertSame('Звёздные товары', $mapping->getCanonicalLabel());

        $this->em->refresh($category);
        self::assertSame(ExternalCategoryStatus::MAPPED, $category->getStatus());
    }

    public function testAdminCanUpdateSeededMapping(): void
    {
        /** @var SeedExternalCategoryMappingsAction $seedAction */
        $seedAction = self::getContainer()->get(SeedExternalCategoryMappingsAction::class);
        $seedAction(IngestSource::OZON);

        $normalizedKey = OzonAccrualCategoryTaxonomyResolver::codeKey('ozon_acquiring');
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

        $auditRows = $this->connection->fetchAllAssociative(
            'SELECT scope, action, company_id, new_values FROM ingest_external_category_mapping_audit WHERE external_category_id = :categoryId',
            ['categoryId' => $category->getId()],
        );
        self::assertCount(1, $auditRows);
        self::assertSame('default', $auditRows[0]['scope']);
        self::assertSame('update', $auditRows[0]['action']);
        self::assertNull($auditRows[0]['company_id']);
    }

    public function testAdminCanCreateCompanyOverrideWithoutChangingDefaultMapping(): void
    {
        /** @var SeedExternalCategoryMappingsAction $seedAction */
        $seedAction = self::getContainer()->get(SeedExternalCategoryMappingsAction::class);
        $seedAction(IngestSource::OZON);

        $normalizedKey = OzonAccrualCategoryTaxonomyResolver::codeKey('PayPerClick');
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

        $companyId = Uuid::uuid7()->toString();

        /** @var UpdateExternalCategoryMappingAction $updateAction */
        $updateAction = self::getContainer()->get(UpdateExternalCategoryMappingAction::class);
        $updateAction(
            categoryId: $category->getId(),
            canonicalCode: 'ozon_company_cpc',
            canonicalLabel: 'Оплата за клик company',
            canonicalGroup: 'Company group',
            transactionType: TransactionType::ADVERTISING,
            sortOrder: 1234,
            status: ExternalCategoryMappingStatus::ACTIVE,
            known: true,
            companyId: $companyId,
        );

        /** @var ExternalCategoryCompanyMappingRepository $companyMappingRepository */
        $companyMappingRepository = self::getContainer()->get(ExternalCategoryCompanyMappingRepository::class);
        $companyMapping = $companyMappingRepository->findByCategoryAndCompany($category, $companyId);

        self::assertInstanceOf(ExternalCategoryCompanyMapping::class, $companyMapping);
        self::assertSame('ozon_company_cpc', $companyMapping->getCanonicalCode());

        /** @var ExternalCategoryMappingRepository $mappingRepository */
        $mappingRepository = self::getContainer()->get(ExternalCategoryMappingRepository::class);
        $defaultMapping = $mappingRepository->findByCategory($category);
        self::assertInstanceOf(ExternalCategoryMapping::class, $defaultMapping);
        self::assertSame('ozon_cpc', $defaultMapping->getCanonicalCode());
        $this->em->refresh($category);
        self::assertSame('Оплата за клик', $category->getDisplayLabel());

        $auditRows = $this->connection->fetchAllAssociative(
            'SELECT scope, action, company_id FROM ingest_external_category_mapping_audit WHERE external_category_id = :categoryId',
            ['categoryId' => $category->getId()],
        );
        self::assertCount(1, $auditRows);
        self::assertSame('company', $auditRows[0]['scope']);
        self::assertSame('create', $auditRows[0]['action']);
        self::assertSame($companyId, $auditRows[0]['company_id']);
    }
}
