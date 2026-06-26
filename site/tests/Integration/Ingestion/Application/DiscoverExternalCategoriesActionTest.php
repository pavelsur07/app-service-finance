<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application;

use App\Ingestion\Application\Action\DiscoverExternalCategoriesAction;
use App\Ingestion\Application\Source\Ozon\OzonAccrualCategoryTaxonomyResolver;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Entity\ExternalCategory;
use App\Ingestion\Entity\ExternalCategoryMapping;
use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Enum\ExternalCategoryMappingStatus;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use App\Ingestion\Repository\ExternalCategoryMappingRepository;
use App\Ingestion\Repository\ExternalCategoryRepository;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class DiscoverExternalCategoriesActionTest extends IntegrationTestCase
{
    public function testDeduplicatesCategoryAndMappingIdentityWithinSingleRun(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $rawRecordId = Uuid::uuid7()->toString();

        $this->em->persist($this->unknownOzonFeeTransaction(
            companyId: $companyId,
            rawRecordId: $rawRecordId,
            externalId: 'ozon:accrual-by-day:1:delivery:product-0:service-0:type-29',
            component: 'delivery:product-0:service-0:type-29',
            label: 'Неизвестная категория Ozon: Logistic',
            externalCode: 'ozon_logistics',
        ));
        $this->em->persist($this->unknownOzonFeeTransaction(
            companyId: $companyId,
            rawRecordId: $rawRecordId,
            externalId: 'ozon:accrual-by-day:2:delivery:product-1:service-0:type-29',
            component: 'delivery:product-1:service-0:type-29',
            label: 'Неизвестная категория Ozon: Logistic duplicate label',
            externalCode: 'ozon_logistics',
        ));
        $this->em->flush();

        /** @var DiscoverExternalCategoriesAction $action */
        $action = self::getContainer()->get(DiscoverExternalCategoriesAction::class);
        $stats = $action(IngestSource::OZON, 10);

        self::assertSame(2, $stats['scanned']);
        self::assertSame(1, $stats['categoriesCreated']);
        self::assertSame(1, $stats['categoriesSeen']);
        self::assertSame(1, $stats['autoMapped']);
        self::assertSame(0, $stats['unmapped']);

        /** @var ExternalCategoryRepository $categoryRepository */
        $categoryRepository = self::getContainer()->get(ExternalCategoryRepository::class);
        $category = $categoryRepository->findByIdentity(
            IngestSource::OZON,
            OzonResourceType::ACCRUAL_BY_DAY,
            OzonAccrualCategoryTaxonomyResolver::SCOPE_DELIVERY,
            'code:ozon_logistics',
        );

        self::assertInstanceOf(ExternalCategory::class, $category);
        self::assertSame(2, $category->getSeenCount());

        /** @var ExternalCategoryMappingRepository $mappingRepository */
        $mappingRepository = self::getContainer()->get(ExternalCategoryMappingRepository::class);
        $mapping = $mappingRepository->findByCategory($category);

        self::assertInstanceOf(ExternalCategoryMapping::class, $mapping);
        self::assertSame(ExternalCategoryMappingStatus::ACTIVE, $mapping->getStatus());
        self::assertSame('ozon_logistics', $mapping->getCanonicalCode());
    }

    private function unknownOzonFeeTransaction(
        string $companyId,
        string $rawRecordId,
        string $externalId,
        string $component,
        string $label,
        ?string $externalCode = null,
    ): FinancialTransaction {
        return new FinancialTransaction(
            companyId: $companyId,
            connectionRef: 'connection-1',
            shopRef: 'shop-1',
            source: IngestSource::OZON,
            externalId: $externalId,
            externalUpdatedAt: new \DateTimeImmutable('2026-06-15 10:00:00+00:00'),
            operationGroupId: Uuid::uuid7()->toString(),
            type: TransactionType::FEE,
            direction: TransactionDirection::OUT,
            money: Money::fromMinor(100, 'RUB'),
            occurredAt: new \DateTimeImmutable('2026-06-15 10:00:00+00:00'),
            rawRecordId: $rawRecordId,
            description: $label,
            sourceData: [
                '_ingestion_resource' => OzonResourceType::ACCRUAL_BY_DAY,
                '_ingestion_type_id' => '29',
                '_ingestion_external_code' => $externalCode,
                '_ingestion_component' => $component,
                '_ozon_category_known' => false,
                '_ozon_category_group' => 'Неизвестные категории Ozon',
                '_ozon_category_label' => $label,
            ],
        );
    }
}
