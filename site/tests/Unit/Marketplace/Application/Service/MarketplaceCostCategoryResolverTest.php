<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Service;

use App\Company\Entity\Company;
use App\Marketplace\Application\Service\MarketplaceCostCategoryResolver;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceCostCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class MarketplaceCostCategoryResolverTest extends TestCase
{
    public function testResolvesCorrectCategoryPerCompany(): void
    {
        $companyA = $this->createMock(Company::class);
        $companyA->method('getId')->willReturn('company-a-id');

        $companyB = $this->createMock(Company::class);
        $companyB->method('getId')->willReturn('company-b-id');

        $categoryA = $this->createMock(MarketplaceCostCategory::class);

        $repository = $this->createMock(MarketplaceCostCategoryRepository::class);
        $repository
            ->expects(self::exactly(2))
            ->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use ($companyA, $categoryA): ?MarketplaceCostCategory {
                if ($criteria['company'] === $companyA) {
                    return $categoryA;
                }

                return null;
            });

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');

        $resolver = new MarketplaceCostCategoryResolver($repository, $em);

        $resultA = $resolver->resolve($companyA, MarketplaceType::OZON, 'logistics', 'Логистика');
        $resultB = $resolver->resolve($companyB, MarketplaceType::OZON, 'logistics', 'Логистика');

        self::assertSame($categoryA, $resultA);
        self::assertNotSame($resultA, $resultB);
        self::assertSame($companyB, $resultB->getCompany());
    }

    public function testClearCacheResetsAllEntries(): void
    {
        $company = $this->createMock(Company::class);
        $company->method('getId')->willReturn('company-id');

        $category = $this->createMock(MarketplaceCostCategory::class);

        $repository = $this->createMock(MarketplaceCostCategoryRepository::class);
        $repository
            ->expects(self::exactly(2))
            ->method('findOneBy')
            ->willReturn($category);

        $em = $this->createMock(EntityManagerInterface::class);

        $resolver = new MarketplaceCostCategoryResolver($repository, $em);

        self::assertSame($category, $resolver->resolve($company, MarketplaceType::OZON, 'logistics', 'Логистика'));
        $resolver->clearCache();
        self::assertSame($category, $resolver->resolve($company, MarketplaceType::OZON, 'logistics', 'Логистика'));
    }

    /**
     * Regression: resolve() must persist but NOT flush when creating a new category.
     *
     * Side-flush inside resolve() was the root cause of "EntityManagerClosed" cascades
     * and "Multiple non-persisted new entities" — a DBAL failure on this flush closed
     * the EM for the rest of the batch, and a successful flush committed half-built
     * cost rows from the enclosing loop.
     */
    public function testResolveDoesNotFlushWhenCreatingNewCategory(): void
    {
        $company = $this->createMock(Company::class);
        $company->method('getId')->willReturn('company-id');

        $repository = $this->createMock(MarketplaceCostCategoryRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::never())->method('flush');

        $resolver = new MarketplaceCostCategoryResolver($repository, $em);

        $category = $resolver->resolve(
            $company,
            MarketplaceType::OZON,
            'logistics',
            'Логистика',
        );

        self::assertNotSame('', $category->getId());
        self::assertSame('logistics', $category->getCode());
        self::assertSame('Логистика', $category->getName());
    }

    /**
     * Regression: resetCache() must drop cache entries whose rows are missing from the DB.
     *
     * Old getReference-based resetCache silently created proxies for dangling IDs.
     * The new implementation must re-fetch via findBy and evict entries that no
     * longer exist (e.g., a category that was persist()-ed but never flushed).
     */
    public function testResetCacheDropsCategoriesMissingFromDatabase(): void
    {
        $company = $this->createMock(Company::class);
        $company->method('getId')->willReturn('company-id');

        $survivor = $this->createMock(MarketplaceCostCategory::class);
        $survivor->method('getId')->willReturn('cat-survivor');

        $dangling = $this->createMock(MarketplaceCostCategory::class);
        $dangling->method('getId')->willReturn('cat-dangling');

        $freshSurvivor = $this->createMock(MarketplaceCostCategory::class);
        $freshSurvivor->method('getId')->willReturn('cat-survivor');

        $repository = $this->createMock(MarketplaceCostCategoryRepository::class);
        $repository
            ->method('findOneBy')
            ->willReturnOnConsecutiveCalls($survivor, $dangling);
        $repository
            ->expects(self::once())
            ->method('findBy')
            ->with(self::callback(static fn (array $criteria): bool => isset($criteria['id'])
                && in_array('cat-survivor', $criteria['id'], true)
                && in_array('cat-dangling', $criteria['id'], true)
            ))
            ->willReturn([$freshSurvivor]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getReference');

        $resolver = new MarketplaceCostCategoryResolver($repository, $em);

        $resolver->resolve($company, MarketplaceType::OZON, 'logistics', 'Логистика');
        $resolver->resolve($company, MarketplaceType::OZON, 'commission', 'Комиссия');

        $resolver->resetCache();

        // Survivor resolves from refreshed cache (no extra findOneBy call).
        self::assertSame(
            $freshSurvivor,
            $resolver->resolve($company, MarketplaceType::OZON, 'logistics', 'Логистика'),
        );
    }
}
