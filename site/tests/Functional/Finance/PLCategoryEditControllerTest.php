<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance;

use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLExpenseType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class PLCategoryEditControllerTest extends WebTestCaseBase
{
    public function testEditPreservesStoredExpenseTypeWhenSubmittedUnchanged(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $category = PLCategoryBuilder::aPLCategory()
            ->forCompany($company)
            ->withName('Variable expense')
            ->build()
            ->setExpenseType(PLExpenseType::VARIABLE);

        $em = $this->em();
        $em->persist($user);
        $em->persist($company);
        $em->persist($category);
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', '/pl-categories/'.$category->getId().'/edit');

        self::assertResponseIsSuccessful();
        $expenseTypeField = $crawler->filter('select[id$="_expenseType"]');
        self::assertCount(1, $expenseTypeField);

        $form = $crawler->filter('#pl-category-edit-form')->form();
        $client->submit($form);

        self::assertResponseRedirects('/pl-categories/');

        $em->clear();
        $updatedCategory = $this->em()->getRepository(PLCategory::class)->find($category->getId());

        self::assertInstanceOf(PLCategory::class, $updatedCategory);
        self::assertSame(PLExpenseType::VARIABLE, $updatedCategory->getExpenseType());
    }
}
