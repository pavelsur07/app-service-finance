<?php

namespace App\DataFixtures;

use App\Company\Entity\Company;
use App\Entity\PLCategory;
use App\Enum\PLExpenseType;
use App\Enum\PLFlow;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Ramsey\Uuid\Nonstandard\Uuid;

final class PLCategoryFixtures extends Fixture implements DependentFixtureInterface
{
    public const REF_SALES_WB = 'pl.sales_wb';
    public const REF_SALES_OZON = 'pl.sales_ozon';
    public const REF_COGS_MATERIALS = 'pl.cogs_materials';
    public const REF_COGS_PRODUCTION = 'pl.cogs_production';
    public const REF_OPEX_MARKETING = 'pl.opex_marketing';
    public const REF_OPEX_RENT = 'pl.opex_rent';
    public const REF_OPEX_PAYROLL = 'pl.opex_payroll';

    public function load(ObjectManager $manager): void
    {
        /** @var Company $company */
        $company = $this->getReference(AppFixtures::REF_COMPANY_ROMASHKA, Company::class);

        $make = function (
            string $name,
            string $code,
            int $sort,
            ?PLCategory $parent = null,
            PLFlow $flow = PLFlow::NONE,
            PLExpenseType $expenseType = PLExpenseType::OTHER,
            ?string $ref = null,
        ) use ($company, $manager): PLCategory {
            $category = new PLCategory(Uuid::uuid4()->toString(), $company);
            $category->setName($name);
            $category->setCode($code);
            $category->setSortOrder($sort);
            $category->setParent($parent);
            $category->setFlow($flow);
            $category->setExpenseType($expenseType);

            $manager->persist($category);

            if (null !== $ref) {
                $this->addReference($ref, $category);
            }

            return $category;
        };

        $revenue = $make('REVENUE', 'REVENUE', 10);
        $expense = $make('EXPENSE', 'EXPENSE', 20);

        $make('SALES_WB', 'SALES_WB', 11, $revenue, PLFlow::INCOME, PLExpenseType::OTHER, self::REF_SALES_WB);
        $make('SALES_OZON', 'SALES_OZON', 12, $revenue, PLFlow::INCOME, PLExpenseType::OTHER, self::REF_SALES_OZON);

        $make('COGS_MATERIALS', 'COGS_MATERIALS', 21, $expense, PLFlow::EXPENSE, PLExpenseType::VARIABLE, self::REF_COGS_MATERIALS);
        $make('COGS_PRODUCTION', 'COGS_PRODUCTION', 22, $expense, PLFlow::EXPENSE, PLExpenseType::VARIABLE, self::REF_COGS_PRODUCTION);
        $make('OPEX_MARKETING', 'OPEX_MARKETING', 23, $expense, PLFlow::EXPENSE, PLExpenseType::OPEX, self::REF_OPEX_MARKETING);
        $make('OPEX_RENT', 'OPEX_RENT', 24, $expense, PLFlow::EXPENSE, PLExpenseType::OPEX, self::REF_OPEX_RENT);
        $make('OPEX_PAYROLL', 'OPEX_PAYROLL', 25, $expense, PLFlow::EXPENSE, PLExpenseType::OPEX, self::REF_OPEX_PAYROLL);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [AppFixtures::class];
    }
}
