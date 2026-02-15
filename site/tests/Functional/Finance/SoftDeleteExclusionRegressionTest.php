<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;

final class SoftDeleteExclusionRegressionTest extends WebTestCaseBase
{
    public function testTransactionsStatementExcludesSoftDeletedTransactions(): void
    {
        $client = static::createClient();
        [$user, $company, $account] = $this->prepareCompanyContext();

        $activeTx = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            CashDirection::INFLOW,
            '100.00',
            'RUB',
            new \DateTimeImmutable('2024-01-05')
        );
        $activeTx->setDescription('VISIBLE-STATEMENT-TX');

        $deletedTx = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            CashDirection::OUTFLOW,
            '50.00',
            'RUB',
            new \DateTimeImmutable('2024-01-06')
        );
        $deletedTx->setDescription('DELETED-STATEMENT-TX');
        $deletedTx->markDeleted('tester', 'regression');

        $em = $this->em();
        $em->persist($activeTx);
        $em->persist($deletedTx);
        $em->flush();

        $this->loginWithActiveCompany($client, $user, $company->getId());
        $client->request('GET', '/finance/reports/transactions-statement', [
            'date_from' => '2024-01-01',
            'date_to' => '2024-01-31',
            'expand_transactions' => '1',
        ]);

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('VISIBLE-STATEMENT-TX', $content);
        self::assertStringNotContainsString('DELETED-STATEMENT-TX', $content);
    }

    public function testOpsCheckExcludesSoftDeletedTransactionsInSqlReport(): void
    {
        $client = static::createClient();
        [$user, $company, $account] = $this->prepareCompanyContext();

        $activeTx = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            CashDirection::INFLOW,
            '100.00',
            'RUB',
            new \DateTimeImmutable('2024-01-05')
        );
        $activeTx->setExternalId('OPS-ACTIVE-EXT');
        $activeTx->setImportSource('bank1c');

        $deletedTx = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            CashDirection::OUTFLOW,
            '70.00',
            'RUB',
            new \DateTimeImmutable('2024-01-06')
        );
        $deletedTx->setExternalId('OPS-DELETED-EXT');
        $deletedTx->setImportSource('bank1c');
        $deletedTx->markDeleted('tester', 'regression');

        $em = $this->em();
        $em->persist($activeTx);
        $em->persist($deletedTx);
        $em->flush();

        $this->loginWithActiveCompany($client, $user, $company->getId());
        $client->request('GET', '/finance/reports/cashflow-ops-check', [
            'date_from' => '2024-01-01',
            'date_to' => '2024-01-31',
            'account' => $account->getId(),
        ]);

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('OPS-ACTIVE-EXT', $content);
        self::assertStringNotContainsString('OPS-DELETED-EXT', $content);
    }

    public function testAutoRuleCheckPreviewExcludesSoftDeletedTransactions(): void
    {
        $client = static::createClient();
        [$user, $company, $account] = $this->prepareCompanyContext();

        $category = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        $category->setName('Rule category');

        $rule = new CashTransactionAutoRule(
            Uuid::uuid4()->toString(),
            $company,
            'All operations',
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::ANY,
            $category,
        );

        $activeTx = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            CashDirection::INFLOW,
            '200.00',
            'RUB',
            new \DateTimeImmutable('2024-01-07')
        );
        $activeTx->setDescription('AUTO-RULE-ACTIVE-DESC');

        $deletedTx = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            CashDirection::INFLOW,
            '300.00',
            'RUB',
            new \DateTimeImmutable('2024-01-08')
        );
        $deletedTx->setDescription('AUTO-RULE-DELETED-DESC');
        $deletedTx->markDeleted('tester', 'regression');

        $em = $this->em();
        $em->persist($category);
        $em->persist($rule);
        $em->persist($activeTx);
        $em->persist($deletedTx);
        $em->flush();

        $this->loginWithActiveCompany($client, $user, $company->getId());
        $client->request('GET', sprintf('/cash-transaction-auto-rules/%s/check', $rule->getId()));

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('AUTO-RULE-ACTIVE-DESC', $content);
        self::assertStringNotContainsString('AUTO-RULE-DELETED-DESC', $content);
    }

    /** @return array{0: \App\Company\Entity\User, 1: \App\Company\Entity\Company, 2: \App\Cash\Entity\Accounts\MoneyAccount} */
    private function prepareCompanyContext(): array
    {
        $this->resetDb();
        $em = $this->em();

        $user = UserBuilder::aUser()
            ->withIndex(random_int(1000, 9999))
            ->asCompanyOwner()
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withIndex(random_int(1000, 9999))
            ->withOwner($user)
            ->build();

        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName('Main account')
            ->build();

        $em->persist($user);
        $em->persist($company);
        $em->persist($account);
        $em->flush();

        return [$user, $company, $account];
    }

    private function loginWithActiveCompany(object $client, object $user, string $companyId): void
    {
        $client->loginUser($user);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', $companyId);
        $session->save();
    }
}
