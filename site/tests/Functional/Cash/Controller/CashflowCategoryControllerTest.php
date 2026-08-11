<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class CashflowCategoryControllerTest extends WebTestCaseBase
{
    public function testNewFormOffersRootAndOnlyAllowedParents(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$user, $company] = $this->persistCompany();

        $operating = $this->category('11111111-1111-4111-8111-111111111111', $company, 'Операционная деятельность')
            ->markAsSystem(CashflowCategory::CODE_OPERATING);
        $technical = $this->category('22222222-2222-4222-8222-222222222222', $company, 'Технические операции')
            ->setFlowKind(CashflowFlowKind::TECHNICAL)
            ->markAsSystem(CashflowCategory::CODE_TECHNICAL);
        $regular = $this->category('33333333-3333-4333-8333-333333333333', $company, 'Аренда');
        $this->em()->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $crawler = $client->request('GET', '/cashflow-categories/new');

        self::assertResponseIsSuccessful();
        self::assertSame(
            'cashflow-category-flow-kind',
            $crawler->filter('#cashflow-category-new-form')->attr('data-controller'),
        );
        $parentOptions = $crawler->filter('select[id$="_parent"] option');
        self::assertSame('— Корневая категория —', trim($parentOptions->first()->text()));
        self::assertEqualsCanonicalizing(
            [$operating->getId(), $regular->getId()],
            $parentOptions->slice(1)->each(static fn ($option): ?string => $option->attr('value')),
        );
        self::assertNotContains($technical->getId(), $parentOptions->each(static fn ($option): ?string => $option->attr('value')));
        self::assertSame(
            ['OPERATING', 'INVESTING', 'FINANCING'],
            $crawler->filter('select[id$="_flowKind"] option')->each(static fn ($option): ?string => $option->attr('value')),
        );
        self::assertSame(
            'change->cashflow-category-flow-kind#toggle',
            $crawler->filter('select[id$="_parent"]')->attr('data-action'),
        );
        self::assertSame(
            'flowKind',
            $crawler->filter('select[id$="_flowKind"]')->attr('data-cashflow-category-flow-kind-target'),
        );
    }

    public function testEditDetachesCategoryAndChangesFlowKindInOneSubmit(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$user, $company] = $this->persistCompany();

        $parent = $this->category('44444444-4444-4444-8444-444444444444', $company, 'Инвестиции')
            ->setFlowKind(CashflowFlowKind::INVESTING);
        $category = $this->category('55555555-5555-4555-8555-555555555555', $company, 'Аренда', $parent)
            ->syncFlowKindWithParent();
        $descendant = $this->category('66666666-6666-4666-8666-666666666666', $company, 'Дочерняя статья', $category);
        $technical = $this->category('77777777-7777-4777-8777-777777777777', $company, 'Технические операции')
            ->setFlowKind(CashflowFlowKind::TECHNICAL)
            ->markAsSystem(CashflowCategory::CODE_TECHNICAL);
        $this->em()->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $crawler = $client->request('GET', '/cashflow-categories/'.$category->getId().'/edit');

        self::assertResponseIsSuccessful();
        $parentValues = $crawler->filter('select[id$="_parent"] option')->each(static fn ($option): ?string => $option->attr('value'));
        self::assertContains($parent->getId(), $parentValues);
        self::assertNotContains($category->getId(), $parentValues);
        self::assertNotContains($descendant->getId(), $parentValues);
        self::assertNotContains($technical->getId(), $parentValues);

        $form = $crawler->filter('#cashflow-category-edit-form')->form();
        $form['cashflow_category[parent]'] = '';
        $form['cashflow_category[flowKind]'] = CashflowFlowKind::OPERATING->value;
        $client->submit($form);

        self::assertResponseRedirects('/cashflow-categories/');
        $this->em()->clear();
        $detached = $this->em()->getRepository(CashflowCategory::class)->find($category->getId());
        self::assertInstanceOf(CashflowCategory::class, $detached);
        self::assertNull($detached->getParent());
        self::assertSame(CashflowFlowKind::OPERATING, $detached->getFlowKind());
    }

    public function testEditingChildWithoutSubmittedFlowKindKeepsInheritedValue(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$user, $company] = $this->persistCompany();

        $parent = $this->category('88888888-8888-4888-8888-888888888888', $company, 'Инвестиции')
            ->setFlowKind(CashflowFlowKind::INVESTING);
        $category = $this->category('99999999-9999-4999-8999-999999999999', $company, 'Оборудование', $parent)
            ->syncFlowKindWithParent();
        $this->em()->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $crawler = $client->request('GET', '/cashflow-categories/'.$category->getId().'/edit');
        $form = $crawler->filter('#cashflow-category-edit-form')->form();
        $values = $form->getPhpValues();
        unset($values['cashflow_category']['flowKind']);
        $values['cashflow_category']['name'] = 'Оборудование и мебель';

        $client->request($form->getMethod(), $form->getUri(), $values);

        self::assertResponseRedirects('/cashflow-categories/');
        $this->em()->clear();
        $updated = $this->em()->getRepository(CashflowCategory::class)->find($category->getId());
        self::assertInstanceOf(CashflowCategory::class, $updated);
        self::assertSame($parent->getId(), $updated->getParent()?->getId());
        self::assertSame(CashflowFlowKind::INVESTING, $updated->getFlowKind());
        self::assertSame('Оборудование и мебель', $updated->getName());
    }

    /** @return array{User, Company} */
    private function persistCompany(): array
    {
        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $this->em()->persist($user);
        $this->em()->persist($company);

        return [$user, $company];
    }

    private function category(
        string $id,
        Company $company,
        string $name,
        ?CashflowCategory $parent = null,
    ): CashflowCategory {
        $category = (new CashflowCategory($id, $company))
            ->setName($name)
            ->setParent($parent);
        $this->em()->persist($category);

        return $category;
    }
}
