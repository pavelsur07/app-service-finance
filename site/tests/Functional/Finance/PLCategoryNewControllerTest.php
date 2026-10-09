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

    public function testNewDoesNotOfferParentAtMaxDepth(): void
    {
        $client = static::createClient();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $chain = [];
        $parent = null;
        foreach (['L1', 'L2', 'L3', 'L4', 'L5'] as $name) {
            $parent = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName($name)->withParent($parent)->build();
            $chain[] = $parent;
        }

        $em = $this->em();
        foreach ([$user, $company, ...$chain] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', '/pl-categories/new');
        self::assertResponseIsSuccessful();

        $parentSelect = $crawler->filter('select[id$="_parent"]');
        $offered = $parentSelect->filter('option')->each(static fn ($node) => $node->attr('value'));
        self::assertContains($chain[3]->getId(), $offered);
        self::assertNotContains($chain[4]->getId(), $offered);

        // Подделанный выбор 5-го уровня — ошибка формы, а не 500.
        $form = $crawler->filter('#pl-category-edit-form')->form();
        $client->request($form->getMethod(), $form->getUri(), array_replace_recursive($form->getPhpValues(), [
            'pl_category_form' => ['name' => 'Too deep', 'parent' => $chain[4]->getId()],
        ]));

        self::assertResponseIsSuccessful();
        self::assertNull($this->em()->getRepository(PLCategory::class)->findOneBy(['company' => $company, 'name' => 'Too deep']));
    }
}
