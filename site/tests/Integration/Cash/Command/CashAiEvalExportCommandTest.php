<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Command;

use App\Cash\Command\CashAiEvalExportCommand;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashCategorySuggestion;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionSplit;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\PaymentPlan\PaymentPlanType;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Company\Entity\Company;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Выгрузка истории ручных категоризаций для золотого набора (docs/tasks/ai/stages/stage-2.md, 2.2).
 */
final class CashAiEvalExportCommandTest extends IntegrationTestCase
{
    private Company $company;
    private MoneyAccount $account;
    private CashflowCategory $rent;
    private CashflowCategory $revenue;

    protected function setUp(): void
    {
        parent::setUp();

        $user = UserBuilder::aUser()->withEmail('ai-export@example.com')->build();
        $this->company = CompanyBuilder::aCompany()->withOwner($user)->withName('Export')->build();
        $this->account = new MoneyAccount(Uuid::uuid4()->toString(), $this->company, MoneyAccountType::BANK, 'Main', 'RUB');
        $this->account->setOpeningBalance('0');
        $this->account->setOpeningBalanceDate(new \DateTimeImmutable('2026-01-01'));
        $expenses = $this->category($this->company, 'Расходы', null);
        $this->rent = $this->category($this->company, 'Аренда', PaymentPlanType::OUTFLOW, $expenses);
        $this->revenue = $this->category($this->company, 'Выручка', PaymentPlanType::INFLOW);
        foreach ([$user, $this->company, $this->account, $expenses, $this->rent, $this->revenue] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    public function testExportsManualCategorizationsInPayloadForm(): void
    {
        $manual = $this->transaction('Оплата аренды офиса', $this->rent, CashTransactionSplitSource::MANUAL, '2026-10-01');
        $this->transaction('Аренда по правилу', $this->rent, CashTransactionSplitSource::AUTO, '2026-10-02');
        $this->transaction('Аренда из импорта', $this->rent, CashTransactionSplitSource::IMPORT, '2026-10-03');
        $this->transaction('Перевод', $this->rent, CashTransactionSplitSource::MANUAL, '2026-10-04', transfer: true);
        $this->transaction('Удалённая', $this->rent, CashTransactionSplitSource::MANUAL, '2026-10-05')->markDeleted(null);
        $accepted = $this->transaction('Принята подсказка ИИ', $this->rent, CashTransactionSplitSource::MANUAL, '2026-10-06');
        $suggestion = new CashCategorySuggestion((string) $this->company->getId(), (string) $accepted->getId(), Uuid::uuid4()->toString(), new \DateTimeImmutable());
        $suggestion->suggest((string) $this->rent->getId(), 0.9, 'accepted', null, null, new \DateTimeImmutable());
        $suggestion->accept(Uuid::uuid4()->toString(), new \DateTimeImmutable());
        $this->em->persist($suggestion);
        $this->foreignManualTransaction();
        $this->em->flush();

        [$status, $export, $summary] = $this->export();

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(CashAiEvalExportCommand::FORMAT, $export['format']);
        self::assertSame([
            ['id' => (string) $this->revenue->getId(), 'path' => 'Выручка', 'direction' => 'inflow'],
            ['id' => (string) $this->rent->getId(), 'path' => 'Расходы / Аренда', 'direction' => 'outflow'],
        ], $export['categories']);
        self::assertSame([[
            'operation' => [
                'id' => (string) $manual->getId(),
                'direction' => 'outflow',
                'amount' => '85000.00',
                'currency' => 'RUB',
                'date' => '2026-10-01',
                'description' => 'Оплата аренды офиса',
                'counterparty' => null,
            ],
            'expected_category_id' => (string) $this->rent->getId(),
        ]], $export['cases']);
        self::assertStringContainsString('1 cases over 1 categories', $summary);
    }

    public function testKeepsLatestOperationsPerCategoryAndSkipsCategoriesThatAreNoLongerLeaves(): void
    {
        foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $date) {
            $this->transaction('Аренда '.$date, $this->rent, CashTransactionSplitSource::MANUAL, $date);
        }
        $this->transaction('Продажа', $this->revenue, CashTransactionSplitSource::MANUAL, '2026-09-04', CashDirection::INFLOW);
        // «Выручка» стала группой: старые кейсы с ней модели не ответить — справочник её не содержит.
        $this->em->persist($this->category($this->company, 'Выручка опт', PaymentPlanType::INFLOW, $this->revenue));
        $this->em->flush();

        [, $export, $summary] = $this->export(['--per-category' => '2']);

        self::assertSame(['Аренда 2026-09-03', 'Аренда 2026-09-02'], array_map(
            static fn (array $case): ?string => $case['operation']['description'],
            $export['cases'],
        ));
        self::assertStringContainsString('1 skipped', $summary);
    }

    public function testInvalidOptionsAreRejected(): void
    {
        $tester = $this->tester();
        self::assertSame(Command::INVALID, $tester->execute(['--company-id' => 'nope'], ['capture_stderr_separately' => true]));
        self::assertSame(Command::INVALID, $tester->execute(['--company-id' => (string) $this->company->getId(), '--limit' => '0'], ['capture_stderr_separately' => true]));
    }

    /**
     * @param array<string, string> $options
     *
     * @return array{0: int, 1: array<string, mixed>, 2: string}
     */
    private function export(array $options = []): array
    {
        $tester = $this->tester();
        $status = $tester->execute(['--company-id' => (string) $this->company->getId(), ...$options], ['capture_stderr_separately' => true]);
        /** @var array<string, mixed> $export */
        $export = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);

        return [$status, $export, $tester->getErrorOutput()];
    }

    private function tester(): CommandTester
    {
        self::assertNotNull(self::$kernel);

        return new CommandTester((new Application(self::$kernel))->find('app:cash:ai-eval-export'));
    }

    private function category(Company $company, string $name, ?PaymentPlanType $type, ?CashflowCategory $parent = null): CashflowCategory
    {
        $category = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        $category->setName($name);
        $category->setOperationType($type);
        $category->setParent($parent);

        return $category;
    }

    private function transaction(
        string $description,
        CashflowCategory $category,
        CashTransactionSplitSource $source,
        string $date,
        CashDirection $direction = CashDirection::OUTFLOW,
        bool $transfer = false,
    ): CashTransaction {
        $builder = CashTransactionBuilder::aCashTransaction()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($this->company)
            ->withMoneyAccount($this->account)
            ->withDirection($direction)
            ->withAmount('85000.00');
        $tx = ($transfer ? $builder->asTransfer() : $builder)->build();
        $tx->setOccurredAt(new \DateTimeImmutable($date));
        $tx->setDescription($description);
        // Билдер со статьёй сам ставит разбивку `manual`; источник здесь — параметр теста.
        $tx->setCashflowCategory($category);
        $tx->replaceSplits([new CashTransactionSplit($tx, $category, '85000.00', $source)]);
        $this->em->persist($tx);

        return $tx;
    }

    private function foreignManualTransaction(): void
    {
        $owner = UserBuilder::aUser()->withIndex(9402)->build();
        $other = CompanyBuilder::aCompany()->withIndex(9402)->withOwner($owner)->build();
        $account = new MoneyAccount(Uuid::uuid4()->toString(), $other, MoneyAccountType::BANK, 'Other', 'RUB');
        $account->setOpeningBalance('0');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('2026-01-01'));
        $category = $this->category($other, 'Аренда', PaymentPlanType::OUTFLOW);
        $tx = CashTransactionBuilder::aCashTransaction()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($other)
            ->withMoneyAccount($account)
            ->withAmount('100.00')
            ->withCashflowCategory($category)
            ->build();
        $tx->setOccurredAt(new \DateTimeImmutable('2026-10-07'));
        $tx->replaceSplits([new CashTransactionSplit($tx, $category, '100.00', CashTransactionSplitSource::MANUAL)]);
        foreach ([$owner, $other, $account, $category, $tx] as $entity) {
            $this->em->persist($entity);
        }
    }
}
