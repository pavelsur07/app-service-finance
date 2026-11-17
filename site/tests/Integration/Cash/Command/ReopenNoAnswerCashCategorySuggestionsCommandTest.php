<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Command;

use App\Cash\Entity\Transaction\CashCategorySuggestion;
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

/** Разовое переоткрытие «нет ответа» ИИ после смены дерева статей ДДС. */
final class ReopenNoAnswerCashCategorySuggestionsCommandTest extends IntegrationTestCase
{
    public function testDryRunCountsAndChangesNothing(): void
    {
        $company = $this->company();
        $noAnswer = $this->suggestion($company, null);

        $tester = $this->runCommand($company);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Будет переоткрыто: 1', $tester->getDisplay());
        self::assertSame('no_answer', $this->statusOf($noAnswer));
    }

    public function testExecuteReopensOnlyNoAnswerOfCompany(): void
    {
        $company = $this->company();
        $other = $this->company();
        $noAnswer = $this->suggestion($company, null);
        $suggested = $this->suggestion($company, Uuid::uuid4()->toString());
        $foreign = $this->suggestion($other, null);

        $tester = $this->runCommand($company, ['--execute' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Переоткрыто: 1', $tester->getDisplay());
        self::assertSame('stale', $this->statusOf($noAnswer));
        self::assertSame('reopened', $this->db()->fetchOne('SELECT error_code FROM cash_category_suggestion WHERE id = ?', [$noAnswer->getId()]));
        self::assertSame('suggested', $this->statusOf($suggested));
        self::assertSame('no_answer', $this->statusOf($foreign));

        $repeat = $this->runCommand($company, ['--execute' => true]);
        self::assertStringContainsString('Переоткрывать нечего', $repeat->getDisplay());
    }

    /** @param array<string, mixed> $options */
    private function runCommand(Company $company, array $options = []): CommandTester
    {
        \assert(null !== self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find('app:cash:ai-suggestions:reopen-no-answer'));
        $tester->execute(['companyId' => (string) $company->getId()] + $options);
        $this->em->clear();

        return $tester;
    }

    private function suggestion(Company $company, ?string $categoryId): CashCategorySuggestion
    {
        $account = MoneyAccountBuilder::aMoneyAccount()->withId(Uuid::uuid4()->toString())->withName('Счёт '.Uuid::uuid4()->toString())->forCompany($company)->build();
        $transaction = new CashTransaction(Uuid::uuid4()->toString(), $company, $account, CashDirection::OUTFLOW, '100.00', 'RUB', new \DateTimeImmutable('2026-11-10'));
        $suggestion = CashCategorySuggestionBuilder::aSuggestion()
            ->withId(Uuid::uuid4()->toString())
            ->withCompanyId((string) $company->getId())
            ->withTransactionId((string) $transaction->getId())
            ->withTaskId(Uuid::uuid4()->toString())
            ->suggesting($categoryId)
            ->build();
        foreach ([$account, $transaction, $suggestion] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        return $suggestion;
    }

    private function company(): Company
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail(sprintf('user+%s@example.test', Uuid::uuid4()->toString()))->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }

    private function statusOf(CashCategorySuggestion $suggestion): string
    {
        return (string) $this->db()->fetchOne('SELECT status FROM cash_category_suggestion WHERE id = ?', [$suggestion->getId()]);
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        return $this->em->getConnection();
    }
}
