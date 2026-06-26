<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Command;

use App\Ingestion\Application\Source\Ozon\OzonAccrualCategoryTaxonomyResolver;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Entity\ExternalCategory;
use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Enum\ExternalCategoryStatus;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use App\Ingestion\Repository\ExternalCategoryRepository;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class MarketplaceCategoryRebuildIdentitiesCommandTest extends IntegrationTestCase
{
    public function testRebuildsCodeLikeExternalNameIntoSemanticIdentity(): void
    {
        $category = new ExternalCategory(
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            scope: OzonAccrualCategoryTaxonomyResolver::SCOPE_NON_ITEM,
            normalizedKey: 'type:999',
            externalTypeId: '999',
            externalName: 'RfbsServiceFee',
            status: ExternalCategoryStatus::NEW,
        );
        $this->em->persist($category);
        $this->em->flush();

        $dryRun = $this->tester();
        self::assertSame(Command::SUCCESS, $dryRun->execute([]));
        $this->em->clear();
        self::assertNull($this->findCategory('code:rfbsservicefee'));

        $execute = $this->tester();
        self::assertSame(Command::SUCCESS, $execute->execute(['--execute' => true]));
        $this->em->clear();

        $rebuilt = $this->findCategory('code:rfbsservicefee');
        self::assertInstanceOf(ExternalCategory::class, $rebuilt);
        self::assertSame('RfbsServiceFee', $rebuilt->getExternalCode());
    }

    public function testDeprecatesStaleTypeOnlyCategoryWhenSemanticCategoryExists(): void
    {
        $staleTypeOnly = new ExternalCategory(
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            scope: OzonAccrualCategoryTaxonomyResolver::SCOPE_NON_ITEM,
            normalizedKey: 'type:55',
            externalTypeId: '55',
            status: ExternalCategoryStatus::NEW,
        );
        $semantic = new ExternalCategory(
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            scope: OzonAccrualCategoryTaxonomyResolver::SCOPE_NON_ITEM,
            normalizedKey: 'code:pushcampaign',
            externalTypeId: '55',
            externalCode: 'PushCampaign',
            externalName: 'PushCampaign',
            providerLabel: 'PushCampaign',
            displayLabel: 'PushCampaign',
            status: ExternalCategoryStatus::NEW,
        );
        $this->em->persist($staleTypeOnly);
        $this->em->persist($semantic);
        $this->em->flush();

        $dryRun = $this->tester();
        self::assertSame(Command::SUCCESS, $dryRun->execute([]));
        $this->em->clear();
        self::assertSame(ExternalCategoryStatus::NEW, $this->findCategory('type:55')?->getStatus());

        $execute = $this->tester();
        self::assertSame(Command::SUCCESS, $execute->execute(['--execute' => true]));
        $this->em->clear();

        self::assertSame(ExternalCategoryStatus::DEPRECATED, $this->findCategory('type:55')?->getStatus());
        self::assertSame(ExternalCategoryStatus::NEW, $this->findCategory('code:pushcampaign')?->getStatus());
    }

    public function testDeprecatesStaleTypeOnlyCategoryWhenSemanticCategoryExistsInAnotherScope(): void
    {
        $staleTypeOnly = new ExternalCategory(
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            scope: OzonAccrualCategoryTaxonomyResolver::SCOPE_ITEM,
            normalizedKey: 'type:55',
            externalTypeId: '55',
            status: ExternalCategoryStatus::NEW,
        );
        $semantic = new ExternalCategory(
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            scope: OzonAccrualCategoryTaxonomyResolver::SCOPE_NON_ITEM,
            normalizedKey: 'code:pushcampaign',
            externalTypeId: '55',
            externalCode: 'PushCampaign',
            externalName: 'PushCampaign',
            providerLabel: 'PushCampaign',
            displayLabel: 'PushCampaign',
            status: ExternalCategoryStatus::NEW,
        );
        $this->em->persist($staleTypeOnly);
        $this->em->persist($semantic);
        $this->em->flush();

        $execute = $this->tester();
        self::assertSame(Command::SUCCESS, $execute->execute(['--execute' => true]));
        $this->em->clear();

        self::assertSame(
            ExternalCategoryStatus::DEPRECATED,
            $this->findCategory('type:55', OzonAccrualCategoryTaxonomyResolver::SCOPE_ITEM)?->getStatus(),
        );
        self::assertSame(ExternalCategoryStatus::NEW, $this->findCategory('code:pushcampaign')?->getStatus());
    }

    public function testDeprecatesStaleTypeOnlyCategoryWhenNoCurrentFallbackTransactionExists(): void
    {
        $staleTypeOnly = new ExternalCategory(
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            scope: OzonAccrualCategoryTaxonomyResolver::SCOPE_DELIVERY,
            normalizedKey: 'type:32',
            externalTypeId: '32',
            status: ExternalCategoryStatus::NEW,
        );
        $this->em->persist($staleTypeOnly);
        $this->em->flush();

        $execute = $this->tester();
        self::assertSame(Command::SUCCESS, $execute->execute(['--execute' => true]));
        $this->em->clear();

        self::assertSame(
            ExternalCategoryStatus::DEPRECATED,
            $this->findCategory('type:32', OzonAccrualCategoryTaxonomyResolver::SCOPE_DELIVERY)?->getStatus(),
        );
    }

    public function testKeepsTypeOnlyCategoryWhenCurrentFallbackTransactionExists(): void
    {
        $activeTypeOnly = new ExternalCategory(
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            scope: OzonAccrualCategoryTaxonomyResolver::SCOPE_DELIVERY,
            normalizedKey: 'type:32',
            externalTypeId: '32',
            status: ExternalCategoryStatus::NEW,
        );
        $this->em->persist($activeTypeOnly);
        $this->em->persist($this->unknownFallbackTransaction('32'));
        $this->em->flush();

        $execute = $this->tester();
        self::assertSame(Command::SUCCESS, $execute->execute(['--execute' => true]));
        $this->em->clear();

        self::assertSame(
            ExternalCategoryStatus::NEW,
            $this->findCategory('type:32', OzonAccrualCategoryTaxonomyResolver::SCOPE_DELIVERY)?->getStatus(),
        );
    }

    private function tester(): CommandTester
    {
        $app = new Application(self::$kernel);

        return new CommandTester($app->find('app:ingestion:marketplace-categories:rebuild-identities'));
    }

    private function findCategory(
        string $normalizedKey,
        string $scope = OzonAccrualCategoryTaxonomyResolver::SCOPE_NON_ITEM,
    ): ?ExternalCategory
    {
        /** @var ExternalCategoryRepository $repository */
        $repository = self::getContainer()->get(ExternalCategoryRepository::class);

        return $repository->findByIdentity(
            IngestSource::OZON,
            OzonResourceType::ACCRUAL_BY_DAY,
            $scope,
            $normalizedKey,
        );
    }

    private function unknownFallbackTransaction(string $typeId): FinancialTransaction
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = Uuid::uuid7()->toString();

        return new FinancialTransaction(
            companyId: $companyId,
            connectionRef: $connectionRef,
            shopRef: $connectionRef,
            source: IngestSource::OZON,
            externalId: sprintf('ozon:accrual-by-day:test:type-%s', $typeId),
            externalUpdatedAt: new \DateTimeImmutable('2026-06-26 00:00:00+00:00'),
            operationGroupId: Uuid::uuid7()->toString(),
            type: TransactionType::FEE,
            direction: TransactionDirection::OUT,
            money: Money::fromMinor(100, 'RUB'),
            occurredAt: new \DateTimeImmutable('2026-06-26 00:00:00+03:00'),
            rawRecordId: Uuid::uuid7()->toString(),
            description: sprintf('Ozon accrual fee %s', $typeId),
            sourceData: [
                '_ingestion_resource' => OzonResourceType::ACCRUAL_BY_DAY,
                '_ingestion_type_id' => $typeId,
                '_ingestion_component' => sprintf('delivery:product-0:service-0:type-%s', $typeId),
                '_ozon_category_known' => false,
                '_ozon_category_label' => sprintf('Неизвестный type_id Ozon: %s', $typeId),
                '_ozon_category_group' => 'Требует классификации',
            ],
            sourceTz: 'Europe/Moscow',
        );
    }
}
