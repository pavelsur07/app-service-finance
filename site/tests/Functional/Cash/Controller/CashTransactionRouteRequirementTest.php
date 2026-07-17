<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class CashTransactionRouteRequirementTest extends WebTestCaseBase
{
    public function testShowRejectsMalformedUuidBeforeController(): void
    {
        $client = static::createClient();
        $client->request('GET', '/finance/cash-transactions/'.str_repeat('-', 36));

        self::assertResponseStatusCodeSame(404);
    }

    public function testManualCreateAcceptsAllowedProjectAndResponsibilityCenterPair(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$user, $company, $account, $project, $center] = $this->seedCompanyGraph();
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', '/finance/cash-transactions/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name="cash_transaction[responsibilityCenterId]"]');

        $form = $crawler->selectButton('Сохранить')->form();
        $form['cash_transaction[occurredAt]'] = '2026-07-18';
        $form['cash_transaction[moneyAccount]']->select($account->getId());
        $form['cash_transaction[direction]']->select('1');
        $form['cash_transaction[amount]'] = '1000';
        $form['cash_transaction[projectDirection]']->select($project->getId());
        $form['cash_transaction[responsibilityCenterId]']->select($center->getId());

        $client->submit($form);
        self::assertResponseRedirects('/finance/cash-transactions/');

        $saved = $this->em()->getRepository(CashTransaction::class)->findOneBy(['company' => $company]);
        self::assertInstanceOf(CashTransaction::class, $saved);
        self::assertSame($project->getId(), $saved->getProjectDirection()?->getId());
        self::assertSame($center->getId(), $saved->getResponsibilityCenterId());
    }

    public function testManualEditPreservesArchivedCurrentResponsibilityCenter(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$user, $company, $account, $project, $center] = $this->seedCompanyGraph();
        $center->archive();
        $tx = new CashTransaction(
            '66666666-6666-6666-8666-000000007621',
            $company,
            $account,
            CashDirection::OUTFLOW,
            '1000',
            'RUB',
            new \DateTimeImmutable('2026-07-18'),
        );
        $tx
            ->setProjectDirection($project)
            ->setResponsibilityCenterId($center->getId());
        $this->em()->persist($tx);
        $this->em()->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', '/finance/cash-transactions/'.$tx->getId().'/edit');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('select[name="cash_transaction[responsibilityCenterId]"]', 'Краснодар [CFO_KRD]');

        $form = $crawler->selectButton('Сохранить')->form();
        $form['cash_transaction[amount]'] = '1001';
        $client->submit($form);
        self::assertResponseRedirects('/finance/cash-transactions/');

        $this->em()->clear();
        $saved = $this->em()->find(CashTransaction::class, $tx->getId());
        self::assertInstanceOf(CashTransaction::class, $saved);
        self::assertSame('1001.00', $saved->getAmount());
        self::assertSame($project->getId(), $saved->getProjectDirection()?->getId());
        self::assertSame($center->getId(), $saved->getResponsibilityCenterId());
    }

    /**
     * @return array{0: \App\Company\Entity\User, 1: \App\Company\Entity\Company, 2: \App\Cash\Entity\Accounts\MoneyAccount, 3: ProjectDirection, 4: FinancialResponsibilityCenter}
     */
    private function seedCompanyGraph(): array
    {
        $user = UserBuilder::aUser()->withIndex(7621)->build();
        $company = CompanyBuilder::aCompany()->withIndex(7621)->withOwner($user)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId('33333333-3333-3333-3333-000000007621')
            ->forCompany($company)
            ->build();
        $systemProject = new ProjectDirection(
            '44444444-4444-4444-4444-000000007621',
            $company,
            'Общий',
            ProjectDirection::CODE_GENERAL,
        );
        $systemCenter = new FinancialResponsibilityCenter(
            $company->getId(),
            FinancialResponsibilityCenter::CODE_GENERAL,
            FinancialResponsibilityCenter::NAME_GENERAL,
        );
        $project = new ProjectDirection(
            '55555555-5555-5555-5555-000000007621',
            $company,
            'Продажа компьютеров',
        );
        $center = new FinancialResponsibilityCenter($company->getId(), 'CFO_KRD', 'Краснодар');

        foreach ([$user, $company, $account, $systemProject, $systemCenter, $project, $center] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->persist(new FinancialResponsibilityCenterProject($company->getId(), $systemProject, $systemCenter));
        $this->em()->persist(new FinancialResponsibilityCenterProject($company->getId(), $project, $center));
        $this->em()->flush();

        return [$user, $company, $account, $project, $center];
    }
}
