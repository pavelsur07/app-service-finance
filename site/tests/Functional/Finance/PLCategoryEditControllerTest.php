<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance;

use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLExpenseType;
use App\Finance\Enum\PLFlow;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class PLCategoryEditControllerTest extends WebTestCaseBase
{
    public function testEditPreservesFieldsExcludedFromSubmission(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $category = PLCategoryBuilder::aPLCategory()
            ->forCompany($company)
            ->withName('Variable expense')
            ->build()
            ->setExpenseType(PLExpenseType::VARIABLE)
            ->setIsVisible(false);
        $longIncomeName = str_repeat('Очень длинное наименование дохода ', 4);
        $incomeCategory = PLCategoryBuilder::aPLCategory()
            ->forCompany($company)
            ->withName($longIncomeName)
            ->withFlow(PLFlow::INCOME)
            ->build()
            ->setCode('INCOME_TOTAL_CODE')
            ->setType(PLCategoryType::SUBTOTAL);
        $expenseCategory = PLCategoryBuilder::aPLCategory()
            ->forCompany($company)
            ->withName('Expense subtotal')
            ->withFlow(PLFlow::EXPENSE)
            ->build()
            ->setCode('EXPENSE_TOTAL_CODE')
            ->setType(PLCategoryType::SUBTOTAL);
        $kpiCategory = PLCategoryBuilder::aPLCategory()
            ->forCompany($company)
            ->withName('Calculated KPI')
            ->withFlow(PLFlow::NONE)
            ->build()
            ->setCode('CALCULATED_KPI_CODE')
            ->setType(PLCategoryType::KPI);
        $subtotalCategory = PLCategoryBuilder::aPLCategory()
            ->forCompany($company)
            ->withName('Neutral subtotal')
            ->withFlow(PLFlow::NONE)
            ->build()
            ->setCode('NEUTRAL_SUBTOTAL_CODE')
            ->setType(PLCategoryType::SUBTOTAL);

        $em = $this->em();
        $em->persist($user);
        $em->persist($company);
        $em->persist($category);
        $em->persist($incomeCategory);
        $em->persist($expenseCategory);
        $em->persist($kpiCategory);
        $em->persist($subtotalCategory);
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', '/pl-categories/'.$category->getId().'/edit');

        self::assertResponseIsSuccessful();
        $expenseTypeField = $crawler->filter('select[id$="_expenseType"]');
        self::assertCount(1, $expenseTypeField);
        self::assertCount(1, $crawler->filter('select[id$="_flow"]'));
        self::assertCount(0, $crawler->filter('input[name$="[flow]"]'));
        self::assertCount(1, $crawler->filter('select[id$="_format"]'));
        self::assertCount(0, $crawler->filter('input[name$="[format]"]'));
        self::assertCount(0, $crawler->filter('input[id$="_isVisible"]'));
        self::assertSame(['INCOME_TOTAL_CODE'], $crawler->filter('#tab-income .pl-category-variable')->each(static fn ($node) => $node->attr('data-insert-code')));
        self::assertSame(['EXPENSE_TOTAL_CODE'], $crawler->filter('#tab-expense .pl-category-variable')->each(static fn ($node) => $node->attr('data-insert-code')));
        self::assertSame(['CALCULATED_KPI_CODE'], $crawler->filter('#tab-calculated .pl-category-variable')->each(static fn ($node) => $node->attr('data-insert-code')));

        $incomeVariableName = $crawler->filter('#tab-income .pl-category-variable-name');
        self::assertCount(1, $incomeVariableName->filter('.text-truncate'));
        self::assertSame($longIncomeName, $incomeVariableName->attr('title'));
        self::assertSame('INCOME_TOTAL_CODE', trim($crawler->filter('#tab-income .pl-category-variable-code')->text()));
        self::assertCount(1, $crawler->filter('#tab-income .pl-category-variable-code.flex-shrink-0.text-nowrap'));

        $form = $crawler->filter('#pl-category-edit-form')->form();
        $client->submit($form);

        self::assertResponseRedirects('/pl-categories/');

        $em->clear();
        $updatedCategory = $this->em()->getRepository(PLCategory::class)->find($category->getId());

        self::assertInstanceOf(PLCategory::class, $updatedCategory);
        self::assertSame(PLExpenseType::VARIABLE, $updatedCategory->getExpenseType());
        self::assertFalse($updatedCategory->isVisible());
    }
}
