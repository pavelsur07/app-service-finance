<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\DTO\CashTransactionDTO;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\PaymentPlan\PaymentPlanType;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Form\Transaction\CashTransactionType;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpKernel\Profiler\Profile;

/**
 * Дерево «Статья ДДС» формы операции: строится из статей своей компании одним
 * запросом и знает, какое поле формы задаёт направление.
 */
final class CashTransactionCategoryTreeTest extends WebTestCaseBase
{
    private int $categoryIndex = 0;

    public function testEditPageQueryCountDoesNotGrowWithNumberOfCategories(): void
    {
        $client = static::createClient();
        [$user, $company, $tx] = $this->prepareTransaction(8101);
        $root = $this->persistCategory($company, 'Выбытие');
        $this->persistCategory($company, 'Комиссия банка', $root);
        $this->em()->flush();

        $this->countEditPageQueries($client, $user, $company, $tx);
        $few = $this->countEditPageQueries($client, $user, $company, $tx);

        $company = $this->em()->find(Company::class, $company->getId()) ?? throw new \LogicException('Компания не найдена.');
        $root = $this->em()->find(CashflowCategory::class, $root->getId()) ?? throw new \LogicException('Статья не найдена.');
        for ($i = 0; $i < 9; ++$i) {
            $group = $this->persistCategory($company, 'Группа '.$i, $root);
            $sub = $this->persistCategory($company, 'Подгруппа '.$i, $group);
            $this->persistCategory($company, 'Лист '.$i, $sub);
        }
        $this->em()->flush();
        $many = $this->countEditPageQueries($client, $user, $company, $tx);

        self::assertSame($few, $many, sprintf('Число запросов выросло с %d до %d при росте дерева статей с 2 до 29.', $few, $many));
    }

    public function testTreeHoldsOnlyOwnCompanyCategoriesAndDirectionOfTheForm(): void
    {
        static::createClient();
        [, $company] = $this->prepareTransaction(8102);
        [, $foreign] = $this->prepareTransaction(8103);
        $income = $this->persistCategory($company, 'Поступления', null, PaymentPlanType::INFLOW);
        $sales = $this->persistCategory($company, 'Продажи', $income);
        $this->persistCategory($foreign, 'Чужая статья');
        $this->em()->flush();
        $this->em()->clear();

        $company = $this->em()->find(Company::class, $company->getId()) ?? throw new \LogicException('Компания не найдена.');
        /** @var FormFactoryInterface $formFactory */
        $formFactory = static::getContainer()->get(FormFactoryInterface::class);
        $view = $formFactory->create(CashTransactionType::class, new CashTransactionDTO(), ['company' => $company, 'csrf_protection' => false])->createView();
        $field = $view->children['cashflowCategory'];

        self::assertSame([[
            'id' => $income->getId(),
            'name' => 'Поступления',
            'children' => [['id' => $sales->getId(), 'name' => 'Продажи', 'flow' => 'in']],
        ]], $field->vars['tree']);
        self::assertSame('cash_transaction[cashflowCategory]', $field->vars['full_name']);

        $direction = $view->children['direction'];
        $values = [];
        foreach ($direction->vars['choices'] as $choice) {
            $values[$choice->data->value] = $choice->value;
        }
        self::assertSame([
            'source' => '[name="cash_transaction[direction]"]',
            'in' => $values[CashDirection::INFLOW->value],
            'out' => $values[CashDirection::OUTFLOW->value],
        ], $field->vars['direction']);
    }

    public function testEditPageRendersPickerOverTheFormSelectAndSavesTheLeaf(): void
    {
        $client = static::createClient();
        [$user, $company, $tx] = $this->prepareTransaction(8104);
        $root = $this->persistCategory($company, 'Выбытие', null, PaymentPlanType::OUTFLOW);
        $leaf = $this->persistCategory($company, 'Комиссия банка', $root);
        $this->em()->flush();
        $this->em()->clear();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $crawler = $client->request('GET', '/finance/cash-transactions/'.$tx->getId().'/edit');
        self::assertResponseIsSuccessful();

        $picker = $crawler->filterXPath('//div[@data-dds-picker][.//select[@name="cash_transaction[cashflowCategory]"]]');
        self::assertCount(1, $picker);
        self::assertCount(1, $picker->filter('select[name="cash_transaction[cashflowCategory]"].dds-picker__native'));
        self::assertSame('[name="cash_transaction[direction]"]', $picker->attr('data-direction-source'));
        self::assertSame([[
            'id' => $root->getId(),
            'name' => 'Выбытие',
            'children' => [['id' => $leaf->getId(), 'name' => 'Комиссия банка', 'flow' => 'out']],
        ]], json_decode((string) $picker->attr('data-tree'), true, flags: \JSON_THROW_ON_ERROR));
        self::assertSame('disabled', $picker->filter(sprintf('option[value="%s"]', $root->getId()))->attr('disabled'));

        $form = $crawler->selectButton('Сохранить')->form();
        $form['cash_transaction[cashflowCategory]'] = (string) $leaf->getId();
        $client->submit($form);
        self::assertResponseRedirects();

        $this->em()->clear();
        $saved = $this->em()->find(CashTransaction::class, $tx->getId());
        self::assertSame($leaf->getId(), $saved?->getCashflowCategory()?->getId());
    }

    private function countEditPageQueries(KernelBrowser $client, User $user, Company $company, CashTransaction $tx): int
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $client->enableProfiler();
        $client->request('GET', '/finance/cash-transactions/'.$tx->getId().'/edit');
        self::assertResponseIsSuccessful();

        $profile = $client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        return $collector->getQueryCount();
    }

    /**
     * @return array{User, Company, CashTransaction}
     */
    private function prepareTransaction(int $index): array
    {
        $user = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($user)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId(sprintf('33333333-3333-3333-3333-%012d', $index))
            ->forCompany($company)
            ->build();
        $tx = new CashTransaction(
            sprintf('66666666-6666-6666-8666-%012d', $index),
            $company,
            $account,
            CashDirection::OUTFLOW,
            '50.00',
            'RUB',
            new \DateTimeImmutable('2026-07-18'),
        );

        $this->em()->persist($user);
        $this->em()->persist($company);
        $this->em()->persist($account);
        $this->em()->persist($tx);
        $this->em()->flush();

        return [$user, $company, $tx];
    }

    private function persistCategory(Company $company, string $name, ?CashflowCategory $parent = null, ?PaymentPlanType $type = null): CashflowCategory
    {
        $category = new CashflowCategory(sprintf('88888888-8888-4888-8888-%012d', ++$this->categoryIndex), $company);
        $category->setName($name)->setSort($this->categoryIndex)->setParent($parent)->setOperationType($type);
        $this->em()->persist($category);

        return $category;
    }
}
