<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Shared\Entity\AuditLog;
use App\Shared\Enum\AuditLogAction;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;

final class CashTransactionAutoRuleCandidateControllerTest extends WebTestCaseBase
{
    public function testReportIsAuthenticatedCompanyScopedAndReadOnly(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $client->request('GET', '/cash-transaction-auto-rules/candidates');
        self::assertResponseRedirects();

        $user = UserBuilder::aUser()->withIndex(1)->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $category = CashflowCategoryBuilder::aCashflowCategory()
            ->withIndex(1)
            ->withCompany($company)
            ->withName('Комиссия банку')
            ->build();
        $otherCategory = CashflowCategoryBuilder::aCashflowCategory()
            ->withIndex(2)
            ->withCompany($company)
            ->withName('Аренда')
            ->build();

        $eligibleAccount = $this->account($company, 1, 'Основной счёт');
        $conflictingAccount = $this->account($company, 2, 'Счёт с конфликтом');
        $autoChangedAccount = $this->account($company, 3, 'Счёт после автоправила');
        $sameDayAccount = $this->account($company, 4, 'Счёт одного дня');
        $nestedAuditAccount = $this->account($company, 6, 'Счёт вложенного аудита');

        $otherUser = UserBuilder::aUser()->withIndex(2)->asCompanyOwner()->build();
        $otherCompany = CompanyBuilder::aCompany()->withIndex(2)->withOwner($otherUser)->build();
        $otherCompanyCategory = CashflowCategoryBuilder::aCashflowCategory()
            ->withIndex(3)
            ->withCompany($otherCompany)
            ->withName('Чужая статья')
            ->build();
        $otherCompanyAccount = $this->account($otherCompany, 5, 'Чужой счёт');

        foreach ([
            $user,
            $company,
            $category,
            $otherCategory,
            $eligibleAccount,
            $conflictingAccount,
            $autoChangedAccount,
            $sameDayAccount,
            $nestedAuditAccount,
            $otherUser,
            $otherCompany,
            $otherCompanyCategory,
            $otherCompanyAccount,
        ] as $entity) {
            $this->em()->persist($entity);
        }

        $today = new \DateTimeImmutable('today');
        $eligible = $this->transactions($company, $eligibleAccount, array_fill(0, 5, $category), 'eligible', $today);
        $blankSource = $this->transactions($company, $eligibleAccount, array_fill(0, 5, $category), '   ', $today);
        $conflicting = $this->transactions(
            $company,
            $conflictingAccount,
            [$category, $category, $category, $category, $otherCategory],
            'conflict',
            $today,
        );
        $autoChanged = $this->transactions($company, $autoChangedAccount, array_fill(0, 5, $category), 'auto', $today);
        $sameDay = $this->transactions(
            $company,
            $sameDayAccount,
            array_fill(0, 5, $category),
            'same-day',
            $today,
            sameDay: true,
        );
        $nestedAudit = $this->transactions(
            $company,
            $nestedAuditAccount,
            array_fill(0, 5, $category),
            'nested',
            $today,
        );
        $foreign = $this->transactions(
            $otherCompany,
            $otherCompanyAccount,
            array_fill(0, 5, $otherCompanyCategory),
            'foreign',
            $today,
        );

        foreach (array_merge($eligible, $blankSource, $conflicting, $autoChanged, $sameDay, $nestedAudit, $foreign) as $transaction) {
            $this->em()->persist($transaction);
        }
        $this->em()->flush();

        $manualAuditAt = new \DateTimeImmutable('-2 hours');
        foreach ([
            [$eligible, $user],
            [$blankSource, $user],
            [$conflicting, $user],
            [$autoChanged, $user],
            [$sameDay, $user],
            [$foreign, $otherUser],
        ] as [$transactions, $actor]) {
            foreach ($transactions as $transaction) {
                $this->em()->persist($this->manualCategoryAudit($transaction, $actor, $manualAuditAt));
            }
        }
        foreach ($nestedAudit as $transaction) {
            $this->em()->persist($this->manualCategoryAudit($transaction, $user, $manualAuditAt, nested: true));
        }
        $this->em()->flush();

        $this->em()->persist(new AuditLog(
            (string) $company->getId(),
            CashTransaction::class,
            (string) $autoChanged[0]->getId(),
            AuditLogAction::UPDATE,
            [
                'correlationId' => Uuid::uuid7()->toString(),
                'autoRules' => ['cashflowCategory' => ['id' => Uuid::uuid4()->toString(), 'revision' => 1]],
                'changes' => ['cashflowCategory' => ['before' => $category->getId(), 'after' => $category->getId()]],
            ],
            $user->getId(),
            new \DateTimeImmutable('-1 hour'),
        ));
        $this->em()->flush();

        $auditCountBefore = $this->em()->getRepository(AuditLog::class)->count([]);

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $client->request('GET', '/cash-transaction-auto-rules/candidates');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.auto-rule-candidates[data-candidate-count="4"]');
        self::assertSelectorCount(4, '.auto-rule-candidate');
        self::assertSelectorTextContains('body', 'Основной счёт');
        self::assertSelectorTextContains('body', 'eligible');
        self::assertSelectorTextContains('body', 'Счёт вложенного аудита');
        self::assertSelectorTextContains('body', 'nested');
        self::assertSelectorTextContains('body', 'Комиссия банку');
        self::assertSelectorTextContains('body', '5 операций / 5 дат');
        self::assertSelectorTextContains('body', '10 операций / 5 дат');
        self::assertSelectorTextNotContains('body', 'Счёт с конфликтом');
        self::assertSelectorTextNotContains('body', 'Счёт после автоправила');
        self::assertSelectorTextNotContains('body', 'Счёт одного дня');
        self::assertSelectorTextNotContains('body', 'Чужой счёт');
        self::assertSelectorTextNotContains('body', 'Чужая статья');
        self::assertSame($auditCountBefore, $this->em()->getRepository(AuditLog::class)->count([]));
    }

    private function account(Company $company, int $index, string $name): MoneyAccount
    {
        return MoneyAccountBuilder::aMoneyAccount()
            ->withId(sprintf('33333333-3333-3333-3333-%012d', $index))
            ->withName($name)
            ->forCompany($company)
            ->build();
    }

    /**
     * @param list<CashflowCategory> $categories
     *
     * @return list<CashTransaction>
     */
    private function transactions(
        Company $company,
        MoneyAccount $account,
        array $categories,
        string $source,
        \DateTimeImmutable $today,
        bool $sameDay = false,
    ): array {
        $transactions = [];
        foreach ($categories as $index => $category) {
            $transaction = CashTransactionBuilder::aCashTransaction()
                ->forCompany($company)
                ->withMoneyAccount($account)
                ->withCashflowCategory($category)
                ->build();
            $transaction
                ->setOccurredAt($sameDay ? $today : $today->modify(sprintf('-%d days', $index)))
                ->setImportSource($source);
            $transactions[] = $transaction;
        }

        return $transactions;
    }

    private function manualCategoryAudit(
        CashTransaction $transaction,
        User $actor,
        \DateTimeImmutable $createdAt,
        bool $nested = false,
    ): AuditLog {
        $change = [null, $transaction->getCashflowCategory()?->getId()];

        return new AuditLog(
            (string) $transaction->getCompany()->getId(),
            CashTransaction::class,
            (string) $transaction->getId(),
            AuditLogAction::UPDATE,
            $nested ? ['changes' => ['cashflowCategory' => $change]] : ['cashflowCategory' => $change],
            $actor->getId(),
            $createdAt,
        );
    }
}
