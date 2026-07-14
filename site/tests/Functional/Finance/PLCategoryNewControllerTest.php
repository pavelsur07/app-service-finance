<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance;

use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLFlow;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class PLCategoryNewControllerTest extends WebTestCaseBase
{
    public function testNewUsesEditUi(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $incomeCategory = PLCategoryBuilder::aPLCategory()
            ->forCompany($company)
            ->withName('Income category')
            ->withFlow(PLFlow::INCOME)
            ->build()
            ->setCode('INCOME_CODE');

        $em = $this->em();
        $em->persist($user);
        $em->persist($company);
        $em->persist($incomeCategory);
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', '/pl-categories/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2.page-title', 'Новая строка ОПиУ');
        self::assertCount(1, $crawler->filter('form#pl-category-edit-form.card'));
        self::assertCount(0, $crawler->filter('#pl-category-delete-form'));
        self::assertCount(0, $crawler->filter('[form="pl-category-delete-form"]'));
        self::assertCount(1, $crawler->filter('select[id$="_flow"]'));
        self::assertCount(1, $crawler->filter('select[id$="_format"]'));
        self::assertCount(0, $crawler->filter('input[id$="_isVisible"]'));
        self::assertSame(['INCOME_CODE'], $crawler->filter('#tab-income .pl-category-variable')->each(static fn ($node) => $node->attr('data-insert-code')));

        $form = $crawler->filter('#pl-category-edit-form')->form();
        $form[$crawler->filter('input[id$="_name"]')->attr('name')] = 'Created category';
        $client->submit($form);

        self::assertResponseRedirects('/pl-categories/');

        $createdCategory = $this->em()->getRepository(PLCategory::class)->findOneBy([
            'company' => $company,
            'name' => 'Created category',
        ]);
        self::assertInstanceOf(PLCategory::class, $createdCategory);
        self::assertTrue($createdCategory->isVisible());
    }
}
