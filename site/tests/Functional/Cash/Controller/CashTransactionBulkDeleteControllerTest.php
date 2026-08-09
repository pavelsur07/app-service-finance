<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Shared\Entity\AuditLog;
use App\Shared\Enum\AuditLogAction;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class CashTransactionBulkDeleteControllerTest extends WebTestCaseBase
{
    public function testListRendersCurrentPageSelectionAndWarningModal(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$user, $company, , $transactions] = $this->seedCompanyWithTransactions(2);
        $this->login($client, $user, $company);

        $crawler = $client->request('GET', '/finance/cash-transactions/');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('.js-cash-transaction-select'));
        self::assertSame(1, $crawler->filter('#cash-transactions-select-all')->count());
        self::assertNotNull($crawler->filter('#bulk-delete-button')->attr('disabled'));
        self::assertSelectorTextContains('#bulkDeleteModal', 'можно восстановить');
        $expectedIds = array_map(static fn (CashTransaction $transaction): string => $transaction->getId(), $transactions);
        $renderedIds = $crawler->filter('.js-cash-transaction-select')->each(
            static fn ($node): string => (string) $node->attr('value'),
        );
        sort($expectedIds);
        sort($renderedIds);
        self::assertSame($expectedIds, $renderedIds);
    }

    public function testDeletesSelectedTransactionsAndPreservesListQuery(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$user, $company, , $transactions] = $this->seedCompanyWithTransactions(2);
        $this->login($client, $user, $company);

        $client->request('POST', '/finance/cash-transactions/bulk-delete?direction=OUTFLOW', [
            '_token' => $this->csrfToken($client, 'cash_transaction_bulk_delete'),
            'transaction_ids' => array_map(
                static fn (CashTransaction $transaction): string => $transaction->getId(),
                $transactions,
            ),
        ]);

        self::assertResponseRedirects('/finance/cash-transactions/?direction=OUTFLOW');
        $activeList = $client->followRedirect();
        self::assertSelectorTextContains('.toast.text-bg-success', 'Удалено транзакций: 2.');
        $this->em()->clear();

        foreach ($transactions as $transaction) {
            $reloaded = $this->em()->find(CashTransaction::class, $transaction->getId());
            self::assertInstanceOf(CashTransaction::class, $reloaded);
            self::assertTrue($reloaded->isDeleted());
            self::assertSame(
                $user->getId(),
                $this->em()->getConnection()->fetchOne(
                    'SELECT deleted_by FROM cash_transaction WHERE id = :id',
                    ['id' => $transaction->getId()],
                ),
            );

            $audit = $this->em()->getRepository(AuditLog::class)->findOneBy([
                'entityId' => $transaction->getId(),
                'action' => AuditLogAction::SOFT_DELETE,
            ]);
            self::assertInstanceOf(AuditLog::class, $audit);
            self::assertSame($user->getId(), $audit->getActorUserId());
            self::assertSame(1, $this->em()->getRepository(AuditLog::class)->count([
                'entityId' => $transaction->getId(),
                'action' => AuditLogAction::SOFT_DELETE,
            ]));
            self::assertSame(
                0,
                $activeList->filter(sprintf('.js-cash-transaction-select[value="%s"]', $transaction->getId()))->count(),
            );
        }

        $deletedList = $client->request('GET', '/finance/cash-transactions/deleted');
        foreach ($transactions as $transaction) {
            self::assertSame(
                1,
                $deletedList->filter(sprintf('form[action="/finance/cash-transactions/%s/restore"]', $transaction->getId()))->count(),
            );
        }
    }

    public function testForeignTransactionMakesWholeRequestFail(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$user, $company, , $ownTransactions] = $this->seedCompanyWithTransactions(1);
        [, , , $foreignTransactions] = $this->seedCompanyWithTransactions(1);
        $this->login($client, $user, $company);

        $this->postSelection($client, [$ownTransactions[0]->getId(), $foreignTransactions[0]->getId()]);

        $this->em()->clear();
        self::assertFalse($this->reload($ownTransactions[0])->isDeleted());
        self::assertFalse($this->reload($foreignTransactions[0])->isDeleted());
    }

    public function testStaleDeletedTransactionMakesWholeRequestFail(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$user, $company, , $transactions] = $this->seedCompanyWithTransactions(2);
        $transactions[1]->markDeleted($user->getId());
        $this->em()->flush();
        $this->login($client, $user, $company);

        $this->postSelection($client, [$transactions[0]->getId(), $transactions[1]->getId()]);

        $this->em()->clear();
        self::assertFalse($this->reload($transactions[0])->isDeleted());
        self::assertTrue($this->reload($transactions[1])->isDeleted());
    }

    public function testLockedTransactionMakesWholeRequestFail(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$user, $company, , $transactions] = $this->seedCompanyWithTransactions(2);
        $company->setFinanceLockBefore(new \DateTimeImmutable('today'));
        $this->em()->flush();
        $this->login($client, $user, $company);

        $this->postSelection($client, [$transactions[0]->getId(), $transactions[1]->getId()]);

        $this->em()->clear();
        self::assertFalse($this->reload($transactions[0])->isDeleted());
        self::assertFalse($this->reload($transactions[1])->isDeleted());
    }

    public function testInvalidCsrfDoesNotDeleteTransaction(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$user, $company, , $transactions] = $this->seedCompanyWithTransactions(1);
        $this->login($client, $user, $company);

        $client->request('POST', '/finance/cash-transactions/bulk-delete', [
            '_token' => 'invalid',
            'transaction_ids' => [$transactions[0]->getId()],
        ]);

        self::assertResponseRedirects('/finance/cash-transactions/');
        $this->em()->clear();
        self::assertFalse($this->reload($transactions[0])->isDeleted());
    }

    /**
     * @return array{User, Company, MoneyAccount, list<CashTransaction>}
     */
    private function seedCompanyWithTransactions(int $count): array
    {
        $user = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail(sprintf('bulk-delete-%s@example.test', Uuid::uuid4()->toString()))
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(Uuid::uuid4()->toString())
            ->withOwner($user)
            ->build();
        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->build();

        $transactions = [];
        for ($index = 0; $index < $count; ++$index) {
            $transactions[] = new CashTransaction(
                Uuid::uuid4()->toString(),
                $company,
                $account,
                CashDirection::OUTFLOW,
                '100.00',
                'RUB',
                new \DateTimeImmutable(0 === $index ? 'yesterday' : 'today'),
            );
        }

        foreach ([$user, $company, $account, ...$transactions] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        return [$user, $company, $account, $transactions];
    }

    private function login(KernelBrowser $client, User $user, Company $company): void
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
    }

    /**
     * @param list<string> $ids
     */
    private function postSelection(KernelBrowser $client, array $ids): void
    {
        $client->request('POST', '/finance/cash-transactions/bulk-delete', [
            '_token' => $this->csrfToken($client, 'cash_transaction_bulk_delete'),
            'transaction_ids' => $ids,
        ]);

        self::assertResponseRedirects('/finance/cash-transactions/');
    }

    private function reload(CashTransaction $transaction): CashTransaction
    {
        $reloaded = $this->em()->find(CashTransaction::class, $transaction->getId());
        self::assertInstanceOf(CashTransaction::class, $reloaded);

        return $reloaded;
    }
}
