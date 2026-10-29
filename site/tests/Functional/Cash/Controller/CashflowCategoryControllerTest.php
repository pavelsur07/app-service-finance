<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Entity\PaymentPlan\PaymentPlan;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Entity\Transaction\CashTransactionSplit;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class CashflowCategoryControllerTest extends WebTestCaseBase
{
    public function testNewFormOffersRootAndOnlyAllowedParents(): void
    {
        $client = static::createClient();
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

    public function testIndexShowsPlDocumentFlagColumnForEveryLevel(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->persistCompany();

        $root = $this->category('44444444-4444-4444-8444-444444444444', $company, 'Продажи');
        $this->category('55555555-5555-4555-8555-555555555555', $company, 'Wildberries', $root)
            ->setAllowPlDocument(true);
        $this->em()->flush();
        $this->em()->clear();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $crawler = $client->request('GET', '/cashflow-categories/');

        self::assertResponseIsSuccessful();
        self::assertSame('ОПиУ', trim($crawler->filter('.cashflow-category-head .cashflow-category-col--pl')->text()));
        self::assertSame(
            ['Продажи' => '—', 'Wildberries' => '✓'],
            array_combine(
                $crawler->filter('.cashflow-category-item .cashflow-category-link')->each(static fn ($link): string => trim($link->text())),
                $crawler->filter('.cashflow-category-item .cashflow-category-col--pl [aria-hidden]')->each(static fn ($cell): string => trim($cell->text())),
            ),
        );
        self::assertSame(
            ['ОПиУ: можно создавать документ'],
            $crawler->filter('.cashflow-category-children .cashflow-category-col--pl .visually-hidden')->each(static fn ($cell): string => trim($cell->text())),
        );
    }

    public function testEditDetachesCategoryAndChangesFlowKindInOneSubmit(): void
    {
        $client = static::createClient();
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

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function usageProvider(): iterable
    {
        yield 'операция' => ['transaction', 'По статье 1 операция ДДС.'];
        yield 'разбивка операции' => ['split', 'По статье 1 операция ДДС.'];
        yield 'платёж в плане' => ['plan', 'По статье 1 платёж в платёжном календаре.'];
        yield 'правило автокатегоризации' => ['rule', 'По статье 1 правило автокатегоризации.'];
    }

    #[DataProvider('usageProvider')]
    public function testDeletingCategoryInUseKeepsItAndShowsReason(string $usage, string $reason): void
    {
        $client = static::createClient();
        [$user, $company] = $this->persistCompany();
        $category = $this->category('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $company, 'Аренда');
        $account = MoneyAccountBuilder::aMoneyAccount()->forCompany($company)->build();
        $this->em()->persist($account);

        match ($usage) {
            'transaction' => $this->em()->persist(
                CashTransactionBuilder::aCashTransaction()->forCompany($company)->withMoneyAccount($account)->withCashflowCategory($category)->build(),
            ),
            'split' => $this->persistSplit(
                CashTransactionBuilder::aCashTransaction()->forCompany($company)->withMoneyAccount($account)->build(),
                $category,
            ),
            'plan' => $this->em()->persist(
                new PaymentPlan('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $company, $category, new \DateTimeImmutable('2026-10-29'), '100.00'),
            ),
            'rule' => $this->em()->persist(
                new CashTransactionAutoRule('cccccccc-cccc-4ccc-8ccc-cccccccccccc', $company, 'Аренда', CashTransactionAutoRuleAction::FILL, CashTransactionAutoRuleOperationType::ANY, $category),
            ),
            default => throw new \LogicException($usage),
        };
        $this->em()->flush();
        $this->em()->clear();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $client->request('POST', '/cashflow-categories/'.$category->getId().'/delete', [
            '_token' => $this->deleteToken($client, (string) $category->getId()),
        ]);

        self::assertResponseRedirects('/cashflow-categories/');
        $crawler = $client->followRedirect();
        self::assertStringContainsString($reason, $crawler->filter('.toast.text-bg-danger')->text());
        $this->em()->clear();
        self::assertNotNull($this->em()->find(CashflowCategory::class, $category->getId()));
    }

    public function testDeletingUnusedCategoryRemovesIt(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->persistCompany();
        $category = $this->category('dddddddd-dddd-4ddd-8ddd-dddddddddddd', $company, 'Реклама');
        $this->em()->flush();
        $this->em()->clear();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $client->request('POST', '/cashflow-categories/'.$category->getId().'/delete', [
            '_token' => $this->deleteToken($client, (string) $category->getId()),
        ]);

        self::assertResponseRedirects('/cashflow-categories/');
        $this->em()->clear();
        self::assertNull($this->em()->find(CashflowCategory::class, $category->getId()));
    }

    public function testIndexDeleteDialogBlocksCategoryInUse(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->persistCompany();
        $used = $this->category('eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', $company, 'Аренда');
        $free = $this->category('ffffffff-ffff-4fff-8fff-ffffffffffff', $company, 'Реклама');
        $account = MoneyAccountBuilder::aMoneyAccount()->forCompany($company)->build();
        $this->em()->persist($account);
        $this->em()->persist(CashTransactionBuilder::aCashTransaction()->forCompany($company)->withMoneyAccount($account)->withCashflowCategory($used)->build());
        $this->em()->flush();
        $this->em()->clear();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $crawler = $client->request('GET', '/cashflow-categories/');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#cashflow-category-confirm[data-confirm-modal]'));
        $usedTrigger = $crawler->filter(sprintf('[data-confirm-action$="/%s/delete"]', $used->getId()));
        self::assertSame('true', $usedTrigger->attr('data-confirm-blocked'));
        self::assertSame('Статью нельзя удалить', $usedTrigger->attr('data-confirm-title'));
        self::assertStringContainsString('По статье 1 операция ДДС.', (string) $usedTrigger->attr('data-confirm-body'));
        self::assertSame('/finance/cash-transactions/?categoryId='.$used->getId(), $usedTrigger->attr('data-confirm-link-href'));
        $freeTrigger = $crawler->filter(sprintf('[data-confirm-action$="/%s/delete"]', $free->getId()));
        self::assertNull($freeTrigger->attr('data-confirm-blocked'));
        self::assertSame('Удалить', $freeTrigger->attr('data-confirm-submit'));
        self::assertSame('danger', $freeTrigger->attr('data-confirm-variant'));
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

    private function persistSplit(CashTransaction $transaction, CashflowCategory $category): void
    {
        $this->em()->persist($transaction);
        $this->em()->persist(new CashTransactionSplit($transaction, $category, '100.00', CashTransactionSplitSource::MANUAL));
    }

    private function deleteToken(KernelBrowser $client, string $categoryId): string
    {
        $crawler = $client->request('GET', '/cashflow-categories/'.$categoryId.'/edit');

        return (string) $crawler->filter('[data-confirm-token]')->attr('data-confirm-token');
    }
}
