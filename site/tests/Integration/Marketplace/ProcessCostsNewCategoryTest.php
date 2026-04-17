<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace;

use App\Marketplace\Application\Service\MarketplaceCostCategoryResolver;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceCostCategoryRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Regression: two cost rows pointing to the same new category must produce
 * exactly one row in marketplace_cost_categories and both costs must reference
 * that same row — no "Multiple non-persisted new entities" error, no duplicate
 * categories on unique key (company, marketplace, code).
 *
 * Reproduces the original bug: before the fix, resolve() flushed internally on
 * each new category. A single flush committed the pending MarketplaceCost
 * rows from the enclosing loop while their category association was in a
 * transient state, producing Doctrine "Multiple non-persisted new entities"
 * errors or unique-constraint violations on retry.
 */
final class ProcessCostsNewCategoryTest extends IntegrationTestCase
{
    public function testTwoCostsForSameNewCategoryProduceOneCategoryRow(): void
    {
        $owner = UserBuilder::aUser()
            ->withId('22222222-2222-2222-2222-000000000010')
            ->withEmail('costs-owner@example.test')
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId('11111111-1111-1111-1111-000000000010')
            ->withOwner($owner)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();

        /** @var MarketplaceCostCategoryResolver $resolver */
        $resolver = self::getContainer()->get(MarketplaceCostCategoryResolver::class);

        // First call creates a new category — must persist() only, no flush.
        $categoryA = $resolver->resolve(
            $company,
            MarketplaceType::OZON,
            'ozon_sale_commission',
            'Комиссия Ozon за продажу',
        );

        // Second call (same code) must return the cached entity — still not flushed.
        $categoryB = $resolver->resolve(
            $company,
            MarketplaceType::OZON,
            'ozon_sale_commission',
            'Комиссия Ozon за продажу',
        );

        self::assertSame(
            $categoryA,
            $categoryB,
            'Resolver must return the same cached instance for identical code.',
        );

        // Persist cost rows referencing the (still unflushed) category.
        $cost1 = new MarketplaceCost(
            Uuid::uuid4()->toString(),
            $company,
            MarketplaceType::OZON,
            $categoryA,
        );
        $cost1->setExternalId('op-1_commission');
        $cost1->setAmount('100.00');
        $cost1->setCostDate(new \DateTimeImmutable('2026-01-15'));
        $cost1->setOperationType(MarketplaceCostOperationType::CHARGE);
        $this->em->persist($cost1);

        $cost2 = new MarketplaceCost(
            Uuid::uuid4()->toString(),
            $company,
            MarketplaceType::OZON,
            $categoryB,
        );
        $cost2->setExternalId('op-2_commission');
        $cost2->setAmount('200.00');
        $cost2->setCostDate(new \DateTimeImmutable('2026-01-16'));
        $cost2->setOperationType(MarketplaceCostOperationType::CHARGE);
        $this->em->persist($cost2);

        // Single flush at the batch boundary must commit both costs + one category
        // without throwing "Multiple non-persisted new entities".
        $this->em->flush();
        $this->em->clear();

        /** @var MarketplaceCostCategoryRepository $categoryRepo */
        $categoryRepo = self::getContainer()->get(MarketplaceCostCategoryRepository::class);
        $found = $categoryRepo->findBy([
            'company'     => $company,
            'marketplace' => MarketplaceType::OZON,
            'code'        => 'ozon_sale_commission',
        ]);

        self::assertCount(1, $found, 'Exactly one category must be persisted for the code.');
        /** @var MarketplaceCostCategory $persistedCategory */
        $persistedCategory = $found[0];

        $conn = $this->em->getConnection();
        $costCategoryIds = $conn->fetchFirstColumn(
            'SELECT category_id FROM marketplace_costs WHERE company_id = :companyId ORDER BY external_id',
            ['companyId' => $company->getId()],
        );

        self::assertCount(2, $costCategoryIds, 'Both costs must be persisted.');
        self::assertSame(
            [$persistedCategory->getId(), $persistedCategory->getId()],
            $costCategoryIds,
            'Both costs must reference the same persisted category row.',
        );
    }
}
