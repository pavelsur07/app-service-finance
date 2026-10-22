<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Service\Category\CashflowSystemCategoryService;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;

/**
 * Двойная отправка формы создания транзакции ДДС: ключ повтора выдаётся при показе формы
 * и становится ID транзакции, вторая отправка возвращает уже созданную.
 */
final class CashTransactionNewIdempotencyTest extends WebTestCaseBase
{
    public function testSameFormSubmittedTwiceCreatesOneTransaction(): void
    {
        $client = static::createClient();
        $suffix = substr(Uuid::uuid4()->toString(), 0, 8);
        $user = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('cash-new-'.$suffix.'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->withOwner($user)->withName('Cash new '.$suffix)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName('RUB '.$suffix)
            ->withCurrency('RUB')
            ->build()
            ->setOpeningBalanceDate(new \DateTimeImmutable('2026-01-01'));
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Общий', ProjectDirection::CODE_GENERAL);
        $center = new FinancialResponsibilityCenter((string) $company->getId(), FinancialResponsibilityCenter::CODE_GENERAL, FinancialResponsibilityCenter::NAME_GENERAL);
        foreach ([$user, $company, $account, $project, $center] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->persist(new FinancialResponsibilityCenterProject((string) $company->getId(), $project, $center));
        $this->em()->flush();
        self::getContainer()->get(CashflowSystemCategoryService::class)->ensureStructure($company);
        $this->em()->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', '/finance/cash-transactions/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Сохранить')->form();
        $key = (string) $form->getValues()['cash_transaction[idempotencyKey]'];
        self::assertTrue(Uuid::isValid($key));

        $form['cash_transaction[occurredAt]'] = '2026-08-09';
        $form['cash_transaction[moneyAccount]'] = (string) $account->getId();
        $form['cash_transaction[direction]'] = '1'; // Отток
        $form['cash_transaction[amount]'] = '1500.00';
        $form['cash_transaction[projectDirection]'] = (string) $project->getId();
        $form['cash_transaction[responsibilityCenterId]'] = (string) $center->getId();
        $values = $form->getPhpValues();

        $client->request($form->getMethod(), $form->getUri(), $values);
        self::assertResponseRedirects();
        $client->request($form->getMethod(), $form->getUri(), $values);
        self::assertResponseRedirects();

        $ids = $this->em()->getConnection()->fetchFirstColumn(
            'SELECT id FROM cash_transaction WHERE company_id = :company',
            ['company' => $company->getId()],
        );
        self::assertSame([$key], $ids);
    }
}
