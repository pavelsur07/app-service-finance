<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Application\DTO\CashTransactionSplitInput;
use App\Cash\Application\DTO\CashTransactionSplitsInput;
use App\Cash\Application\SaveCashTransactionSplitsAction;
use App\Cash\Entity\PaymentPlan\PaymentPlan;
use App\Cash\Entity\PaymentPlan\PaymentPlanMatch;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionSplit;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Cash\Exception\FinancePeriodLockedException;
use App\Cash\Service\Transaction\CashTransactionAutoRuleService;
use App\Company\Entity\Company;
use App\Shared\Entity\AuditLog;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class SaveCashTransactionSplitsActionTest extends IntegrationTestCase
{
    private ?object $account = null;
    private int $companySeq = 0;

    public function testSplitsAreSavedAndLegacyColumnProjectedToUnallocated(): void
    {
        $company = $this->company();
        $transaction = $this->transaction($company, '1000.00');
        $rent = $this->category($company, 'Аренда');
        $ads = $this->category($company, 'Реклама');

        $this->action()($transaction, $this->input([
            [$rent->getId(), '700.00'],
            [$ads->getId(), '300.00'],
        ]));

        self::assertCount(2, $transaction->getSplits());
        self::assertSame('1000.00', $transaction->getSplitsTotal());

        // Колонка проецируется в «Не распределено», а не в NULL: суммы отчёта по колонке
        // остаются верными, и откат не требует уничтожать строки.
        self::assertNotNull($transaction->getCashflowCategory());
        self::assertSame(
            CashflowCategory::CODE_UNALLOCATED,
            $transaction->getCashflowCategory()->getSystemCode(),
        );
    }

    public function testSingleRowKeepsRealCategoryInLegacyColumn(): void
    {
        $company = $this->company();
        $transaction = $this->transaction($company, '1000.00');
        $rent = $this->category($company, 'Аренда');

        $this->action()($transaction, $this->input([[$rent->getId(), '1000.00']]));

        self::assertCount(1, $transaction->getSplits());
        self::assertSame($rent->getId(), $transaction->getCashflowCategory()?->getId());
    }

    public function testSumMismatchIsRejected(): void
    {
        $company = $this->company();
        $transaction = $this->transaction($company, '1000.00');
        $rent = $this->category($company, 'Аренда');
        $ads = $this->category($company, 'Реклама');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('не равна сумме транзакции');

        $this->action()($transaction, $this->input([
            [$rent->getId(), '700.00'],
            [$ads->getId(), '299.99'],
        ]));
    }

    public function testSplitIntoPlDocumentCategoryIsRejected(): void
    {
        $company = $this->company();
        $transaction = $this->transaction($company, '1000.00');
        $supplier = $this->category($company, 'Поставщики');
        $supplier->setAllowPlDocument(true);
        $this->em->flush();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('документах ОПиУ');

        $this->action()($transaction, $this->input([
            [$supplier->getId(), '600.00'],
            [$this->category($company, 'Реклама')->getId(), '400.00'],
        ]));
    }

    public function testForeignCompanyCategoryIsRejected(): void
    {
        $company = $this->company();
        $transaction = $this->transaction($company, '1000.00');
        $foreign = $this->category($this->company(), 'Чужая');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('не найдена в этой компании');

        $this->action()($transaction, $this->input([[$foreign->getId(), '1000.00']]));
    }

    public function testCompositionChangeIsAudited(): void
    {
        $company = $this->company();
        $transaction = $this->transaction($company, '1000.00');
        $rent = $this->category($company, 'Аренда');
        $this->action()($transaction, $this->input([[$rent->getId(), '1000.00']]));

        $before = $this->countSplitAudits($transaction);

        $this->action()($transaction, $this->input([
            [$rent->getId(), '600.00'],
            [$this->category($company, 'Реклама')->getId(), '400.00'],
        ]));

        self::assertSame($before + 1, $this->countSplitAudits($transaction));
    }

    public function testAutoRulesSkipManuallySplitTransaction(): void
    {
        $company = $this->company();
        $transaction = $this->transaction($company, '1000.00');

        $this->action()($transaction, $this->input([
            [$this->category($company, 'Аренда')->getId(), '600.00'],
            [$this->category($company, 'Реклама')->getId(), '400.00'],
        ]));

        $skipReason = self::getContainer()->get(CashTransactionAutoRuleService::class)
            ->getSkipReason($transaction);

        self::assertNotNull($skipReason, 'Ручная разбивка должна выводить транзакцию из-под автоправил.');
        self::assertSame('SKIPPED_MANUAL_SPLIT', $skipReason->value);
    }

    public function testDeletedTransactionCannotBeSplit(): void
    {
        $company = $this->company();
        $transaction = $this->transaction($company, '1000.00');
        $transaction->markDeleted(null);
        $this->em->flush();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Удалённую операцию');

        $this->action()($transaction, $this->input([[$this->category($company, 'Аренда')->getId(), '1000.00']]));
    }

    public function testLockedPeriodTransactionCannotBeSplit(): void
    {
        $company = $this->company();
        $company->setFinanceLockBefore(new \DateTimeImmutable('2026-06-01'));
        $this->em->flush();

        $transaction = $this->transaction($company, '1000.00');

        $this->expectException(FinancePeriodLockedException::class);

        $this->action()($transaction, $this->input([[$this->category($company, 'Аренда')->getId(), '1000.00']]));
    }

    public function testNonLeafCategoryIsRejected(): void
    {
        $company = $this->company();
        $transaction = $this->transaction($company, '1000.00');

        $parent = $this->category($company, 'Родитель');
        $child = $this->category($company, 'Ребёнок');
        $child->setParent($parent);
        $this->em->flush();
        $this->em->refresh($parent);

        // disabled в разметке защитой не является: подделанный POST дошёл бы до сохранения.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('содержит подстатьи');

        $this->action()($transaction, $this->input([[$parent->getId(), '1000.00']]));
    }

    public function testPaymentPlanMatchSurvivesWhenCategoryUnchanged(): void
    {
        $company = $this->company();
        $transaction = $this->transaction($company, '1000.00');
        $rent = $this->category($company, 'Аренда');
        $match = $this->matchPlan($company, $transaction, $rent);

        $this->action()($transaction, $this->input([[$rent->getId(), '1000.00']]));

        self::assertNotNull(
            $this->em->getRepository(PaymentPlanMatch::class)->find($match->getId()),
            'Состав не изменился — матч плана снимать не за что.',
        );
    }

    public function testPaymentPlanMatchIsDroppedWhenSingleCategoryChanges(): void
    {
        $company = $this->company();
        $transaction = $this->transaction($company, '1000.00');
        $rent = $this->category($company, 'Аренда');
        $match = $this->matchPlan($company, $transaction, $rent);

        // Категория сменилась: матч стал ложным, и календарь считал бы план закрытым
        // транзакцией, которая ему больше не соответствует.
        $this->action()($transaction, $this->input([[$this->category($company, 'Реклама')->getId(), '1000.00']]));

        self::assertNull($this->em->getRepository(PaymentPlanMatch::class)->find($match->getId()));
    }

    public function testPaymentPlanMatchIsDroppedOnMultiSplit(): void
    {
        $company = $this->company();
        $transaction = $this->transaction($company, '1000.00');
        $rent = $this->category($company, 'Аренда');
        $match = $this->matchPlan($company, $transaction, $rent);

        $this->action()($transaction, $this->input([
            [$rent->getId(), '600.00'],
            [$this->category($company, 'Реклама')->getId(), '400.00'],
        ]));

        self::assertNull($this->em->getRepository(PaymentPlanMatch::class)->find($match->getId()));
    }

    private function matchPlan(Company $company, CashTransaction $transaction, CashflowCategory $category): PaymentPlanMatch
    {
        $plan = new PaymentPlan(Uuid::uuid4()->toString(), $company, $category, new \DateTimeImmutable('2026-01-15'), '1000.00');
        $match = new PaymentPlanMatch(Uuid::uuid4()->toString(), $company, $plan, $transaction, new \DateTimeImmutable('2026-01-15'));
        $this->em->persist($plan);
        $this->em->persist($match);
        $this->em->flush();

        return $match;
    }

    public function testManualCompositionOverridesAutoSourceOnReusedCategory(): void
    {
        $company = $this->company();
        $transaction = $this->transaction($company, '1000.00');
        $rent = $this->category($company, 'Аренда');

        // Категорию проставило правило.
        $transaction->replaceSplits([
            new CashTransactionSplit($transaction, $rent, '1000.00', CashTransactionSplitSource::AUTO),
        ]);
        $this->em->flush();

        // Человек разбивает операцию и оставляет ту же статью одной из строк. Категория
        // совпала, но выбрал её теперь он — оставить auto значило бы записать чужое
        // решение как своё и вернуть строку под перезапись правилом после схлопывания.
        $this->action()($transaction, $this->input([
            [$rent->getId(), '600.00'],
            [$this->category($company, 'Реклама')->getId(), '400.00'],
        ]));

        foreach ($transaction->getSplits() as $split) {
            self::assertSame(
                CashTransactionSplitSource::MANUAL,
                $split->getSource(),
                'Состав из формы целиком ручной.',
            );
        }
    }

    private function action(): SaveCashTransactionSplitsAction
    {
        return self::getContainer()->get(SaveCashTransactionSplitsAction::class);
    }

    /**
     * @param list<array{0: ?string, 1: string}> $rows
     */
    private function input(array $rows): CashTransactionSplitsInput
    {
        $input = new CashTransactionSplitsInput();
        foreach ($rows as [$categoryId, $amount]) {
            $row = new CashTransactionSplitInput();
            $row->cashflowCategoryId = $categoryId;
            $row->amount = $amount;
            $input->rows[] = $row;
        }

        return $input;
    }

    private function countSplitAudits(CashTransaction $transaction): int
    {
        $logs = $this->em->getRepository(AuditLog::class)->findBy([
            'entityClass' => CashTransaction::class,
            'entityId' => (string) $transaction->getId(),
        ]);

        return count(array_filter($logs, static fn (AuditLog $log): bool => isset($log->getDiff()['splits'])));
    }

    private function company(): Company
    {
        // У пользователя уникальный email: тест создаёт вторую компанию, чтобы проверить
        // отказ на чужой категории, и дефолтный email упёрся бы в уникальный индекс.
        $owner = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail(sprintf('splits-%d-%s@example.test', ++$this->companySeq, substr(Uuid::uuid4()->toString(), 0, 8)))
            ->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();
        $this->account = null;

        return $company;
    }

    private function category(Company $company, string $name): CashflowCategory
    {
        $category = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        $category->setName($name);
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    private function transaction(Company $company, string $amount): CashTransaction
    {
        if (null === $this->account) {
            $this->account = MoneyAccountBuilder::aMoneyAccount()
                ->withId(Uuid::uuid4()->toString())
                ->forCompany($company)
                ->build();
            $this->em->persist($this->account);
            $this->em->flush();
        }

        $transaction = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $this->account,
            CashDirection::OUTFLOW,
            $amount,
            'RUB',
            new \DateTimeImmutable('2026-01-15'),
        );
        $this->em->persist($transaction);
        $this->em->flush();

        return $transaction;
    }
}
