<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Cash;

use App\Cash\Application\Category\ApplyCashflowCoreTemplateAction;
use App\Cash\Application\Category\EnsureCashflowSystemCategoriesAction;
use App\Cash\Application\DTO\CashflowGroupReport;
use App\Cash\Application\Report\BuildCashflowGroupReport;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\ConsolidationMembershipBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use Doctrine\DBAL\ArrayParameterType;
use Ramsey\Uuid\Uuid;

/** Обезличенная группа и нумерация операций из cashflow-group-intragroup/TASK.md. */
trait IntragroupReportFixture
{
    /** @var array<string, Company> */
    private array $companies = [];

    /** @var array<string, MoneyAccount> */
    private array $accounts = [];

    /** @var array<string, array<string, Counterparty>> */
    private array $cards = [];

    private function createGroupFixture(): void
    {
        $this->em->getConnection()->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $id = Uuid::uuid7()->toString();
        $owner = UserBuilder::aUser()->withId($id)->withEmail($id.'@example.test')->build();
        $this->em->persist($owner);
        foreach (['Альфа' => '7700000011', 'Бета' => '7700000022', 'Гамма' => '7700000033'] as $name => $inn) {
            $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withName($name)->withOwner($owner)->build();
            $company->setInn($inn);
            $this->em->persist($company);
            $this->companies[$name] = $company;
        }
        $this->em->flush();

        foreach ($this->companies as $company) {
            $companyId = (string) $company->getId();
            $this->em->persist(ConsolidationMembershipBuilder::aMembership()->forCompany($companyId)->withHead((string) $this->companies['Альфа']->getId())->build());
            self::getContainer()->get(EnsureCashflowSystemCategoriesAction::class)($companyId);
            self::getContainer()->get(ApplyCashflowCoreTemplateAction::class)($companyId);
        }
        foreach ([['0001', 'Альфа', 'ВТБ'], ['0002', 'Альфа', 'Сбер'], ['0003', 'Бета', 'Сбер'], ['0004', 'Гамма', 'Сбер']] as [$suffix, $company, $name]) {
            $account = MoneyAccountBuilder::aMoneyAccount()->withId(Uuid::uuid7()->toString())->withName($name)->forCompany($this->companies[$company])->build();
            $account->setAccountNumber('4080281000000000'.$suffix);
            $this->em->persist($account);
            $this->accounts[$suffix] = $account;
        }

        $this->addCard('Альфа', 'ООО "Бета"', '7700000022');
        $this->addCard('Альфа', 'ООО "Гамма"', null);
        $this->addCard('Альфа', 'ООО "Альфа"', '7700000011');
        $this->addCard('Бета', 'ООО "Альфа"', null);
        $this->addCard('Бета', 'ООО «Альфа»', null);
        $this->addCard('Гамма', 'ООО "Альфа"', null);
        foreach (array_keys($this->companies) as $company) {
            $this->addCard($company, 'Внешний', '7700000099');
        }
        $this->em->flush();
    }

    private function removeCommittedGroupFixture(): void
    {
        $ids = array_values(array_map(static fn (Company $c): string => (string) $c->getId(), $this->companies));
        $connection = $this->em->getConnection();
        $connection->transactional(function () use ($connection, $ids): void {
            foreach (['cash_intragroup_decision', 'cash_transaction', 'money_account', 'counterparty', 'cashflow_categories', 'company_consolidation_member'] as $table) {
                $connection->executeStatement('DELETE FROM '.$table.' WHERE company_id IN (?)', [$ids], [ArrayParameterType::STRING]);
            }
            $connection->executeStatement('DELETE FROM companies WHERE id IN (?)', [$ids], [ArrayParameterType::STRING]);
            $owner = $this->companies['Альфа']->getUser();
            self::assertNotNull($owner);
            $connection->executeStatement('DELETE FROM "user" WHERE id = ?', [$owner->getId()]);
        });
    }

    private function addCard(string $company, string $name, ?string $inn): void
    {
        $card = CounterpartyBuilder::aCounterparty()->withId(Uuid::uuid7()->toString())
            ->withCompany($this->companies[$company])->withName($name)->withInn($inn)->build();
        $this->em->persist($card);
        $this->cards[$company][$name] = $card;
    }

    private function loadNovemberTransactions(): void
    {
        $alpha = 'ООО "Альфа"';
        $beta = 'ООО "Бета"';
        $external = 'Внешний';
        $rows = [
            1 => ['0001', '02', '-100000', $beta, 'OP_OUT_OTHER'],
            2 => ['0001', '03', '-35000', $alpha, 'OP_OUT_OTHER'],
            3 => ['0001', '06', '-95000', $alpha, 'OP_OUT_OTHER'],
            4 => ['0001', '10', '-450000', $beta, 'OP_OUT_OTHER'],
            5 => ['0001', '10', '2500', $external, 'OP_IN_CUSTOMERS'],
            6 => ['0001', '12', '50000', $external, 'OP_IN_CUSTOMERS'],
            7 => ['0001', '02', '2711392.40', $external, 'OP_IN_CUSTOMERS'],
            8 => ['0001', '12', '5656.68', $external, 'INV_IN_INTEREST'],
            9 => ['0001', '01', '-1880', $external, 'OP_OUT_OTHER'],
            10 => ['0002', '03', '35000', $alpha, 'OP_IN_OTHER'],
            11 => ['0002', '06', '95000', $alpha, 'OP_IN_OTHER'],
            12 => ['0002', '10', '260000', $beta, 'OP_IN_OTHER'],
            13 => ['0002', '10', '-57000', 'ООО "Гамма"', 'OP_OUT_OTHER'],
            14 => ['0002', '16', '-2500', $alpha, 'OP_OUT_OTHER'],
            15 => ['0002', '17', '50000', $beta, 'OP_IN_OTHER'],
            16 => ['0002', '10', '-189088.05', $external, 'OP_OUT_SALARY'],
            17 => ['0002', '10', '-108341.98', $external, 'OP_OUT_TAX_OTHER'],
            18 => ['0002', '10', '-43998.84', $external, 'OP_OUT_OTHER'],
            19 => ['0003', '02', '100000', $alpha, 'OP_IN_OTHER'],
            20 => ['0003', '10', '450000', 'ООО «Альфа»', 'OP_IN_OTHER'],
            21 => ['0003', '10', '-260000', $alpha, 'OP_OUT_OTHER'],
            22 => ['0003', '13', '2600000', $alpha, 'OP_IN_OTHER'],
            23 => ['0003', '17', '150000', $alpha, 'OP_IN_OTHER'],
            24 => ['0003', '17', '-50000', $alpha, 'OP_OUT_OTHER'],
            25 => ['0003', '18', '8786', $external, 'OP_IN_CUSTOMERS'],
            26 => ['0003', '13', '-2623165', $external, 'OP_OUT_MARKETING'],
            27 => ['0003', '10', '-47500', $external, 'OP_OUT_SALARY'],
            28 => ['0003', '10', '-5850.58', $external, 'OP_OUT_TAX_OTHER'],
            29 => ['0003', '10', '-322694.20', $external, 'OP_OUT_OTHER'],
            30 => ['0004', '10', '57000', $alpha, 'OP_IN_OTHER'],
            31 => ['0004', '10', '-35018', $external, 'OP_OUT_SALARY'],
            32 => ['0004', '10', '-18615.40', $external, 'OP_OUT_TAX_OTHER'],
            33 => ['0004', '01', '-2400', $external, 'OP_OUT_OTHER'],
        ];
        foreach ($rows as $number => [$account, $day, $amount, $card, $code]) {
            $this->addTransaction($number, $account, '2026-11-'.$day, $amount, $card, $code);
        }
        $this->em->flush();
    }

    private function loadPeriodTransactions(): void
    {
        $this->loadNovemberTransactions();
        $this->fillMissingInns();
        foreach ($this->accounts as $account) {
            $account->setOpeningBalance('0.00');
            $account->setOpeningBalanceDate(new \DateTimeImmutable('2026-10-01'));
        }
        $this->addTransaction(34, '0002', '2026-11-30', '-40000', 'ООО "Бета"', 'OP_OUT_OTHER');
        $this->addTransaction(35, '0003', '2026-12-02', '40000', 'ООО "Альфа"', 'OP_IN_OTHER');
        $this->addTransaction(36, '0004', '2026-12-03', '5000', 'Внешний', 'OP_IN_CUSTOMERS');
        $this->addTransaction(37, '0001', '2026-10-15', '10000', 'Внешний', 'OP_IN_CUSTOMERS');
        $this->em->flush();
    }

    private function addTransaction(int $number, string $account, string $date, string $signedAmount, string $card, string $code): void
    {
        $moneyAccount = $this->accounts[$account];
        $company = $moneyAccount->getCompany();
        $category = $this->em->getRepository(CashflowCategory::class)->findOneBy(['company' => $company, 'groupCode' => $code]);
        \assert($category instanceof CashflowCategory);
        $transaction = CashTransactionBuilder::aCashTransaction()->withId(self::transactionId($number))
            ->forCompany($company)->withMoneyAccount($moneyAccount)->withCashflowCategory($category)
            ->withOccurredAt(new \DateTimeImmutable($date))->withAmount(ltrim($signedAmount, '-'))
            ->withDirection(str_starts_with($signedAmount, '-') ? CashDirection::OUTFLOW : CashDirection::INFLOW)->build();
        $transaction->setCounterparty($this->cards[(string) $company->getName()][$card]);
        $this->em->persist($transaction);
    }

    private static function transactionId(int $number): string
    {
        return sprintf('01931310-0000-7000-8000-%012d', $number);
    }

    private function fillMissingInns(): void
    {
        $this->cards['Альфа']['ООО "Гамма"']->assignTaxIds('7700000033', null);
        $this->cards['Бета']['ООО "Альфа"']->assignTaxIds('7700000011', null);
        $this->cards['Бета']['ООО «Альфа»']->assignTaxIds('7700000011', null);
        $this->cards['Гамма']['ООО "Альфа"']->assignTaxIds('7700000011', null);
        $this->em->flush();
    }

    private function report(string $from = '2026-11-01', string $to = '2026-11-18', ?string $group = null): CashflowGroupReport
    {
        $report = self::getContainer()->get(BuildCashflowGroupReport::class)((string) $this->companies['Альфа']->getId(), new \DateTimeImmutable($from), new \DateTimeImmutable($to), $group);
        self::assertInstanceOf(CashflowGroupReport::class, $report);

        return $report;
    }
}
