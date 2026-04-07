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

        $resolver = new MarketplaceCostCategoryResolver($repository, $em);

        $resultA = $resolver->resolve($companyA, MarketplaceType::OZON, 'logistics', 'Логистика');
        $resultB = $resolver->resolve($companyB, MarketplaceType::OZON, 'logistics', 'Логистика');

        self::assertSame($categoryA, $resultA);
        self::assertNotSame($resultA, $resultB);
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

        $resolver->resolve($company, MarketplaceType::OZON, 'logistics', 'Логистика');
        $resolver->clearCache();
        $resolver->resolve($company, MarketplaceType::OZON, 'logistics', 'Логистика');
    }
}
