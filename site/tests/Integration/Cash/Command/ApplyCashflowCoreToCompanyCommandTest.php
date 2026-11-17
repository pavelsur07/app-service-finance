<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Command;

use App\Cash\Application\Category\EnsureCashflowSystemCategoriesAction;
use App\Cash\Application\Category\ReplaceUnusedCashflowTreeWithCoreAction;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashCategorySuggestion;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Entity\Company;
use App\Tests\Builders\Cash\CashCategorySuggestionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ApplyCashflowCoreToCompanyCommandTest extends IntegrationTestCase
{
    /** Узлов в core.yaml v1: 7 групп и 30 статей. */
    private const CORE_NODES = 37;

    public function testDryRunPrintsPlanAndChangesNothing(): void
    {
        $company = $this->companyWithOldTree();

        $tester = $this->runCommand($company);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Дивиденды выплаченные', $tester->getDisplay());
        self::assertStringContainsString('Режим просмотра', $tester->getDisplay());
        self::assertSame(['Дивиденды выплаченные', 'Проценты полученные'], $this->regularNames($company));
    }

    public function testExecuteReplacesUnusedTreeWithCore(): void
    {
        $company = $this->companyWithOldTree();
        $other = $this->companyWithOldTree();
        $interest = $this->category($company, 'Проценты полученные');
        $suggestion = $this->suggestion($company, $interest);

        $tester = $this->runCommand($company, ['--execute' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $names = $this->regularNames($company);
        self::assertCount(self::CORE_NODES, $names);
        self::assertNotContains('Дивиденды выплаченные', $names);
        self::assertContains('Поступления от покупателей', $names);
        self::assertSame('stale', $this->db()->fetchOne('SELECT status FROM cash_category_suggestion WHERE id = ?', [$suggestion->getId()]));
        self::assertSame(['Дивиденды выплаченные', 'Проценты полученные'], $this->regularNames($other), 'Чужая компания не тронута.');
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM cash_transaction WHERE company_id = ?', [$company->getId()]));
    }

    /** Пользователь отклонил подсказку после плана: решение не перезаписывается в stale. */
    public function testSuggestionDismissedAfterPlanKeepsUserDecision(): void
    {
        $company = $this->companyWithOldTree();
        $suggestion = $this->suggestion($company, $this->category($company, 'Проценты полученные'));
        $action = self::getContainer()->get(ReplaceUnusedCashflowTreeWithCoreAction::class);
        self::assertSame(1, $action->plan((string) $company->getId())->staleSuggestions);

        $this->db()->executeStatement("UPDATE cash_category_suggestion SET status = 'dismissed' WHERE id = ?", [$suggestion->getId()]);
        $this->em->wrapInTransaction(fn (): int => $action((string) $company->getId()));

        self::assertSame('dismissed', $this->db()->fetchOne('SELECT status FROM cash_category_suggestion WHERE id = ?', [$suggestion->getId()]));
        self::assertCount(self::CORE_NODES, $this->regularNames($company));
    }

    public function testNestedUnusedTreeIsRemovedFromLeaves(): void
    {
        $company = $this->companyWithOldTree();
        $group = $this->category($company, 'Дивиденды выплаченные');
        $child = (new CashflowCategory(Uuid::uuid7()->toString(), $company))->setName('Вложенная')->setParent($group);
        $grandchild = (new CashflowCategory(Uuid::uuid7()->toString(), $company))->setName('Ещё глубже')->setParent($child);
        $this->em->persist($child);
        $this->em->persist($grandchild);
        $this->em->flush();

        $tester = $this->runCommand($company, ['--execute' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertCount(self::CORE_NODES, $this->regularNames($company));
    }

    public function testUsedCategoryRefusesAndKeepsTree(): void
    {
        $company = $this->companyWithOldTree();
        $this->transaction($company)->setCashflowCategory($this->category($company, 'Дивиденды выплаченные'));
        $this->em->flush();

        $tester = $this->runCommand($company, ['--execute' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Дивиденды выплаченные', $tester->getDisplay());
        self::assertSame(['Дивиденды выплаченные', 'Проценты полученные'], $this->regularNames($company));
    }

    public function testRepeatedExecuteIsNoOp(): void
    {
        $company = $this->companyWithOldTree();
        $this->runCommand($company, ['--execute' => true]);
        $ids = $this->regularIds($company);

        $tester = $this->runCommand($company, ['--execute' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('уже применено', $tester->getDisplay());
        self::assertSame($ids, $this->regularIds($company));
    }

    public function testCompanyWithoutRegularCategoriesGetsCore(): void
    {
        $company = $this->company();

        $tester = $this->runCommand($company, ['--execute' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertCount(self::CORE_NODES, $this->regularNames($company));
        self::assertSame(7, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM cashflow_categories WHERE company_id = ? AND is_system', [$company->getId()]));
    }

    public function testUnknownCompanyFails(): void
    {
        self::assertSame(Command::FAILURE, $this->tester()->execute(['companyId' => Uuid::uuid4()->toString(), '--execute' => true]));
    }

    /** @param array<string, mixed> $options */
    private function runCommand(Company $company, array $options = []): CommandTester
    {
        $tester = $this->tester();
        $tester->execute(['companyId' => (string) $company->getId()] + $options);

        return $tester;
    }

    private function tester(): CommandTester
    {
        \assert(null !== self::$kernel);

        return new CommandTester((new Application(self::$kernel))->find('app:cash:cashflow-core:apply'));
    }

    /** Старое дерево как у Максимедиа: системные группы и статьи под финансовой и инвестиционной. */
    private function companyWithOldTree(): Company
    {
        $company = $this->company();
        self::getContainer()->get(EnsureCashflowSystemCategoriesAction::class)((string) $company->getId());
        foreach ([CashflowCategory::CODE_FINANCING => 'Дивиденды выплаченные', CashflowCategory::CODE_INVESTING => 'Проценты полученные'] as $code => $name) {
            $parent = $this->em->getRepository(CashflowCategory::class)->findOneBy(['company' => $company, 'systemCode' => $code]);
            \assert($parent instanceof CashflowCategory);
            $category = (new CashflowCategory(Uuid::uuid7()->toString(), $company))->setName($name)->setParent($parent);
            $category->syncFlowKindWithParent();
            $this->em->persist($category);
        }
        $this->em->flush();

        return $company;
    }

    private function suggestion(Company $company, CashflowCategory $category): CashCategorySuggestion
    {
        $suggestion = CashCategorySuggestionBuilder::aSuggestion()
            ->withId(Uuid::uuid4()->toString())
            ->withCompanyId((string) $company->getId())
            ->withTransactionId((string) $this->transaction($company)->getId())
            ->withTaskId(Uuid::uuid4()->toString())
            ->suggesting((string) $category->getId())
            ->build();
        $this->em->persist($suggestion);
        $this->em->flush();

        return $suggestion;
    }

    private function category(Company $company, string $name): CashflowCategory
    {
        $category = $this->em->getRepository(CashflowCategory::class)->findOneBy(['company' => $company, 'name' => $name]);
        \assert($category instanceof CashflowCategory);

        return $category;
    }

    /** @return list<string> */
    private function regularNames(Company $company): array
    {
        return $this->db()->fetchFirstColumn('SELECT name FROM cashflow_categories WHERE company_id = ? AND NOT is_system ORDER BY name', [$company->getId()]);
    }

    /** @return list<string> */
    private function regularIds(Company $company): array
    {
        return $this->db()->fetchFirstColumn('SELECT id FROM cashflow_categories WHERE company_id = ? AND NOT is_system ORDER BY id', [$company->getId()]);
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        return $this->em->getConnection();
    }

    private function company(): Company
    {
        $owner = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail(sprintf('user+%s@example.test', Uuid::uuid4()->toString()))
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(Uuid::uuid4()->toString())
            ->withOwner($owner)
            ->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }

    private function transaction(Company $company): CashTransaction
    {
        $account = $this->em->getRepository(MoneyAccount::class)->findOneBy(['company' => $company]);
        if (null === $account) {
            $account = MoneyAccountBuilder::aMoneyAccount()
                ->withId(Uuid::uuid4()->toString())
                ->forCompany($company)
                ->build();
            $this->em->persist($account);
            $this->em->flush();
        }

        $transaction = new CashTransaction(Uuid::uuid4()->toString(), $company, $account, CashDirection::OUTFLOW, '1000.00', 'RUB', new \DateTimeImmutable('2026-01-15'));
        $this->em->persist($transaction);
        $this->em->flush();

        return $transaction;
    }
}
