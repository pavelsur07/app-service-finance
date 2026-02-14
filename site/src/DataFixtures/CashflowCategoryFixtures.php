<?php

namespace App\DataFixtures;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use App\Company\Entity\Company;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Ramsey\Uuid\Nonstandard\Uuid;

final class CashflowCategoryFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        /** @var Company $company */
        $company = $this->getReference(AppFixtures::REF_COMPANY_ROMASHKA, Company::class);

        $make = function (
            string $name,
            CashflowFlowKind $flowKind,
            bool $isSystem,
            ?string $systemCode,
            int $sort,
        ) use ($company, $manager): void {
            $category = new CashflowCategory(
                id: Uuid::uuid4()->toString(),
                company: $company,
            );
            $category->setName($name);
            $category->setFlowKind($flowKind);
            $category->setIsSystem($isSystem);
            $category->setSystemCode($systemCode);
            $category->setSort($sort);

            $manager->persist($category);
        };

        $make('Продажи', CashflowFlowKind::OPERATING, false, null, 10);
        $make('Аренда', CashflowFlowKind::OPERATING, false, null, 20);
        $make('CAPEX', CashflowFlowKind::INVESTING, true, 'CAPEX', 30);
        $make('INTERNAL TRANSFER', CashflowFlowKind::OPERATING, true, 'INTERNAL_TRANSFER', 40);
        $make('REFUND_SUPPLIER', CashflowFlowKind::OPERATING, true, 'REFUND_SUPPLIER', 50);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [AppFixtures::class];
    }
}
