<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance\Repository;

use App\Company\Entity\Company;
use App\Finance\Entity\PLCategory;
use App\Finance\Repository\PLCategoryRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\ORM\PersistentCollection;

final class PLCategoryRepositoryTreeTest extends IntegrationTestCase
{
    public function testTreeIsDepthFirstBySortOrderAndBuiltWithoutLazyLoadingChildren(): void
    {
        $company = $this->persistCompany(1);
        $foreign = $this->persistCompany(2);

        $a = $this->category($company, 'A', 20);
        $b = $this->category($company, 'B', 10);
        $this->category($company, 'B2', 20, $b);
        $b1 = $this->category($company, 'B1', 10, $b);
        $this->category($company, 'B1a', 10, $b1);
        $this->category($company, 'A1', 10, $a);
        $this->category($foreign, 'Foreign', 0);
        $this->em->flush();
        $this->em->clear();

        $company = $this->em->find(Company::class, $company->getId());
        self::assertInstanceOf(Company::class, $company);

        $tree = self::getContainer()->get(PLCategoryRepository::class)->findTreeByCompany($company);

        self::assertSame(
            ['B', 'B1', 'B1a', 'B2', 'A', 'A1'],
            array_map(static fn (PLCategory $c): string => $c->getName(), $tree),
        );

        foreach ($tree as $category) {
            $children = $category->getChildren();
            self::assertInstanceOf(PersistentCollection::class, $children);
            self::assertFalse($children->isInitialized(), sprintf('Дети «%s» подгружены лениво — дерево снова стоит запроса на узел.', $category->getName()));
        }
    }

    private function persistCompany(int $index): Company
    {
        $user = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($user)->build();
        $this->em->persist($user);
        $this->em->persist($company);

        return $company;
    }

    private function category(Company $company, string $name, int $sortOrder, ?PLCategory $parent = null): PLCategory
    {
        $category = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName($name)->withParent($parent)->build();
        $category->setSortOrder($sortOrder);
        $this->em->persist($category);

        return $category;
    }
}
