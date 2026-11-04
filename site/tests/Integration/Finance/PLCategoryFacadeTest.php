<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLFlow;
use App\Finance\Facade\PLCategoryFacade;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class PLCategoryFacadeTest extends IntegrationTestCase
{
    private const COMPANY_ID = '0193bbbb-0000-7000-8000-00000000f001';
    private const SUBTOTAL_ID = '0193bbbb-0000-7000-8000-00000000f011';
    private const LEAF_ID = '0193bbbb-0000-7000-8000-00000000f012';

    public function testTreeAndSingleCategoryCarryType(): void
    {
        $owner = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->withOwner($owner)->build();
        $subtotal = PLCategoryBuilder::aPLCategory()->withId(self::SUBTOTAL_ID)->forCompany($company)->withName('Расходы')->withType(PLCategoryType::SUBTOTAL)->build();
        $leaf = PLCategoryBuilder::aPLCategory()->withId(self::LEAF_ID)->forCompany($company)->withName('Аренда')->withParent($subtotal)->build();
        foreach ([$owner, $company, $subtotal, $leaf] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->em->clear();

        $facade = self::getContainer()->get(PLCategoryFacade::class);

        $types = [];
        foreach ($facade->getTreeByCompanyId(self::COMPANY_ID) as $category) {
            $types[$category->id] = $category->type;
        }
        self::assertSame([self::SUBTOTAL_ID => PLCategoryType::SUBTOTAL, self::LEAF_ID => PLCategoryType::LEAF_INPUT], $types);
        $single = $facade->findByIdAndCompany(self::LEAF_ID, self::COMPANY_ID);
        self::assertNotNull($single);
        self::assertSame(PLCategoryType::LEAF_INPUT, $single->type);
        self::assertSame(PLFlow::EXPENSE, $single->flow);
        self::assertTrue($single->isVisible);
    }
}
