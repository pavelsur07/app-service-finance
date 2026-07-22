<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Infrastructure\Query;

use App\Ingestion\Application\Source\Ozon\OzonAccrualCategoryTaxonomyResolver;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Entity\ExternalCategory;
use App\Ingestion\Entity\ExternalCategoryMapping;
use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Enum\ExternalCategoryMappingStatus;
use App\Ingestion\Enum\ExternalCategoryStatus;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use App\Ingestion\Infrastructure\Query\ExternalCategoryAdminQuery;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

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

    public function testUnclassifiedOzonAccrualTransactionsRespectsOptionalWindow(): void
    {
        $this->persistUnclassifiedTransaction('on-from-day', '2026-06-01 00:00:00+03:00');
        $this->persistUnclassifiedTransaction('mid-window', '2026-06-10 00:00:00+03:00');
        $this->persistUnclassifiedTransaction('on-to-day', '2026-06-25 00:00:00+03:00');
        $this->persistUnclassifiedTransaction('before-window', '2026-05-20 00:00:00+03:00');
        $this->persistUnclassifiedTransaction('day-after-to', '2026-06-26 00:00:00+03:00');

        /** @var ExternalCategoryAdminQuery $query */
        $query = self::getContainer()->get(ExternalCategoryAdminQuery::class);

        $global = $query->unclassifiedOzonAccrualTransactions();
        self::assertSame(5, $global['transactions']);

        $windowed = $query->unclassifiedOzonAccrualTransactions(
            new \DateTimeImmutable('2026-06-01 00:00:00'),
            new \DateTimeImmutable('2026-06-25 00:00:00'),
        );
        self::assertSame(3, $windowed['transactions']);
        self::assertSame(1, $windowed['groups']);
    }

    private function persistUnclassifiedTransaction(string $suffix, string $occurredAt): void
    {
        $companyId = Uuid::uuid7()->toString();

        $this->em->persist(new FinancialTransaction(
            companyId: $companyId,
            connectionRef: $companyId,
            shopRef: $companyId,
            source: IngestSource::OZON,
            externalId: sprintf('ozon:accrual-by-day:test-unclassified-%s', $suffix),
            externalUpdatedAt: new \DateTimeImmutable($occurredAt),
            operationGroupId: Uuid::uuid5(Uuid::NAMESPACE_URL, sprintf('%s:ozon:unclassified', $companyId))->toString(),
            type: TransactionType::FEE,
            direction: TransactionDirection::OUT,
            money: Money::fromMinor(100, 'RUB'),
            occurredAt: new \DateTimeImmutable($occurredAt),
            rawRecordId: Uuid::uuid7()->toString(),
            description: sprintf('Ozon accrual unclassified test %s', $suffix),
            sourceData: [
                '_ingestion_resource' => OzonResourceType::ACCRUAL_BY_DAY,
                '_ingestion_type_id' => '999',
                '_ozon_category_label' => 'Неизвестный type_id Ozon: 999',
                '_ozon_category_group' => 'Требует классификации',
                '_ozon_category_known' => false,
            ],
            sourceTz: 'Europe/Moscow',
        ));
        $this->em->flush();
    }
}
