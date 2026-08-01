<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Service\Transaction;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionSplit;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Cash\Service\Transaction\CashTransactionSplitSynchronizer;
use App\Company\Entity\Company;
use App\Shared\Entity\AuditLog;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Dual-write: строки разбивки обязаны повторять cash_transaction.cashflow_category_id
 * один в один, пока читатели живут на колонке.
 */
final class CashTransactionSplitSynchronizerTest extends IntegrationTestCase
{
    private CashTransactionSplitSynchronizer $synchronizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->synchronizer = self::getContainer()->get(CashTransactionSplitSynchronizer::class);
    }

    public function testCategoryIsMirroredIntoSingleSplit(): void
    {
        [$company, $transaction] = $this->transaction('1000.00');
        $category = $this->category($company, 'Аренда');
        $transaction->setCashflowCategory($category);

        $this->synchronizer->sync($transaction, CashTransactionSplitSource::MANUAL);
        $this->em->flush();

        self::assertCount(1, $transaction->getSplits());
        self::assertSame('1000.00', $transaction->getSplitsTotal());
        self::assertSame(
            $category->getId(),
            $transaction->getSplits()->first()->getCashflowCategory()->getId(),
        );
    }

    public function testEmptyCategoryMeansNoSplits(): void
    {
        [$company, $transaction] = $this->transaction('1000.00');
        $transaction->setCashflowCategory($this->category($company, 'Аренда'));
        $this->synchronizer->sync($transaction, CashTransactionSplitSource::MANUAL);
        $this->em->flush();

        $transaction->setCashflowCategory(null);
        $this->synchronizer->sync($transaction, CashTransactionSplitSource::MANUAL);
        $this->em->flush();

        self::assertCount(0, $transaction->getSplits());
    }

    public function testAmountChangeIsFollowedBySplit(): void
    {
        [$company, $transaction] = $this->transaction('1000.00');
        $transaction->setCashflowCategory($this->category($company, 'Аренда'));
        $this->synchronizer->sync($transaction, CashTransactionSplitSource::MANUAL);
        $this->em->flush();

        $transaction->setAmount('2500.00');
        $this->synchronizer->sync($transaction, CashTransactionSplitSource::MANUAL);
        $this->em->flush();

        self::assertSame('2500.00', $transaction->getSplitsTotal());
    }

    public function testSplitSurvivesAmountChangeAfterReload(): void
    {
        [$company, $transaction] = $this->transaction('1000.00');
        $transaction->setCashflowCategory($this->category($company, 'Аренда'));
        $this->synchronizer->sync($transaction, CashTransactionSplitSource::MANUAL);
        $this->em->flush();

        $transaction->setAmount('2500.00');
        $this->synchronizer->sync($transaction, CashTransactionSplitSource::MANUAL);
        $this->em->flush();

        $id = (string) $transaction->getId();
        $this->em->clear();

        $reloaded = $this->em->find(CashTransaction::class, $id);
        self::assertInstanceOf(CashTransaction::class, $reloaded);
        self::assertCount(1, $reloaded->getSplits(), 'Строка не должна исчезнуть из БД после изменения суммы.');
        self::assertSame('2500.00', $reloaded->getSplitsTotal());
    }

    public function testCategoryChangeReplacesRowInDatabase(): void
    {
        [$company, $transaction] = $this->transaction('1000.00');
        $transaction->setCashflowCategory($this->category($company, 'Аренда'));
        $this->synchronizer->sync($transaction, CashTransactionSplitSource::MANUAL);
        $this->em->flush();

        $ads = $this->category($company, 'Реклама');
        $transaction->setCashflowCategory($ads);
        $this->synchronizer->sync($transaction, CashTransactionSplitSource::MANUAL);
        $this->em->flush();

        $id = (string) $transaction->getId();
        $this->em->clear();

        $reloaded = $this->em->find(CashTransaction::class, $id);
        self::assertInstanceOf(CashTransaction::class, $reloaded);
        self::assertCount(1, $reloaded->getSplits(), 'Старая строка должна быть удалена, новая — вставлена.');
        self::assertSame($ads->getId(), $reloaded->getSplits()->first()->getCashflowCategory()->getId());
        self::assertSame('1000.00', $reloaded->getSplitsTotal());
    }

    public function testSyncAlwaysMirrorsColumnEvenOverManualSplit(): void
    {
        [$company, $transaction] = $this->transaction('1000.00');
        $transaction->setCashflowCategory($this->category($company, 'Аренда'));
        $this->synchronizer->sync($transaction, CashTransactionSplitSource::MANUAL);
        $this->em->flush();

        // Колонку до этого места уже защитили режимы автоправил: SAFE заполняет только
        // пустое, REPLACE_AUTO_ASSIGNED — только доказанно авто-назначенное. Если колонка
        // всё же изменилась, строки обязаны за ней последовать, иначе они разойдутся.
        $ads = $this->category($company, 'Реклама');
        $transaction->setCashflowCategory($ads);
        $this->synchronizer->sync($transaction, CashTransactionSplitSource::AUTO);
        $this->em->flush();

        self::assertCount(1, $transaction->getSplits());
        self::assertSame($ads->getId(), $transaction->getSplits()->first()->getCashflowCategory()->getId());
        self::assertSame(CashTransactionSplitSource::AUTO, $transaction->getSplits()->first()->getSource());
    }

    public function testDirectSplitMutationIsRejectedOnFlush(): void
    {
        [$company, $transaction] = $this->transaction('1000.00');
        $transaction->setCashflowCategory($this->category($company, 'Аренда'));
        $this->synchronizer->sync($transaction, CashTransactionSplitSource::MANUAL);
        $this->em->flush();

        // Обход агрегата: строку меняют напрямую, минуя replaceSplits().
        $transaction->getSplits()->first()->changeAmount('1.00');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('replaceSplits');

        $this->em->flush();
    }

    public function testNewSplitMutatedBeforeFirstFlushIsRejected(): void
    {
        [$company, $transaction] = $this->transaction('1000.00');
        $transaction->setCashflowCategory($this->category($company, 'Аренда'));
        $this->synchronizer->sync($transaction, CashTransactionSplitSource::MANUAL);

        // Строка ещё не сохранялась — только PreUpdate такую правку не поймал бы.
        $transaction->getSplits()->first()->changeAmount('1.00');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('replaceSplits');

        $this->em->flush();
    }

    public function testSplitLoadedThroughRepositoryAfterClearIsStillGuarded(): void
    {
        [$company, $transaction] = $this->transaction('1000.00');
        $transaction->setCashflowCategory($this->category($company, 'Аренда'));
        $this->synchronizer->sync($transaction, CashTransactionSplitSource::MANUAL);
        $this->em->flush();

        $transactionId = (string) $transaction->getId();
        $this->em->clear();

        // Коллекция владельца не инициализирована — ранний выход по «не загружено»
        // здесь и открывал обход агрегата.
        $split = $this->em->getRepository(CashTransactionSplit::class)
            ->findOneBy(['cashTransaction' => $transactionId]);
        self::assertInstanceOf(CashTransactionSplit::class, $split);

        $split->changeAmount('1.00');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('replaceSplits');

        $this->em->flush();
    }

    public function testSourceOfFirstSplitComesFromProvenanceNotFromOperation(): void
    {
        // Legacy-транзакция: категория есть, строки ещё нет (окно до backfill).
        [$company, $transaction] = $this->transaction('1000.00');
        $transaction->setCashflowCategory($this->category($company, 'Аренда'));
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->find(CashTransaction::class, (string) $transaction->getId());
        self::assertInstanceOf(CashTransaction::class, $reloaded);
        self::assertCount(0, $reloaded->getSplits());

        // Правило меняет только ЦФО и передаёт AUTO, но категорию оно не трогало —
        // истории авто-назначения нет, значит категоризация ручная.
        $reloaded->setResponsibilityCenterId(null);
        $this->synchronizer->sync($reloaded, CashTransactionSplitSource::AUTO);
        $this->em->flush();

        self::assertSame(
            CashTransactionSplitSource::MANUAL,
            $reloaded->getSplits()->first()->getSource(),
            'source описывает происхождение категоризации, а не текущую операцию.',
        );
    }

    public function testSourceSurvivesAmountOnlyChange(): void
    {
        [$company, $transaction] = $this->transaction('1000.00');
        $transaction->setCashflowCategory($this->category($company, 'Аренда'));
        $this->synchronizer->sync($transaction, CashTransactionSplitSource::AUTO);
        $this->em->flush();

        // Пользователь правит только сумму: происхождение категоризации не меняется.
        $transaction->setAmount('1500.00');
        $this->synchronizer->sync($transaction, CashTransactionSplitSource::MANUAL);
        $this->em->flush();

        self::assertSame('1500.00', $transaction->getSplitsTotal());
        self::assertSame(
            CashTransactionSplitSource::AUTO,
            $transaction->getSplits()->first()->getSource(),
            'source описывает категоризацию, а не сумму.',
        );
    }

    public function testManualMultiSplitSurvivesSync(): void
    {
        [$company, $transaction] = $this->transaction('1000.00');
        $rent = $this->category($company, 'Аренда');
        $ads = $this->category($company, 'Реклама');

        $transaction->replaceSplits([
            new CashTransactionSplit($transaction, $rent, '700.00', CashTransactionSplitSource::MANUAL),
            new CashTransactionSplit($transaction, $ads, '300.00', CashTransactionSplitSource::MANUAL),
        ]);
        $this->em->flush();

        $transaction->setCashflowCategory($rent);
        $this->synchronizer->sync($transaction, CashTransactionSplitSource::MANUAL);
        $this->em->flush();

        self::assertCount(2, $transaction->getSplits(), 'Ручная мультиразбивка не должна схлопываться.');
        self::assertSame('1000.00', $transaction->getSplitsTotal());
    }

    public function testChangingCompositionIsAudited(): void
    {
        [$company, $transaction] = $this->transaction('1000.00');
        $transaction->setCashflowCategory($this->category($company, 'Аренда'));
        $this->synchronizer->sync($transaction, CashTransactionSplitSource::MANUAL);
        $this->em->flush();

        $before = $this->countSplitAuditLogs($transaction);

        $transaction->setCashflowCategory($this->category($company, 'Реклама'));
        $this->synchronizer->sync($transaction, CashTransactionSplitSource::MANUAL);
        $this->em->flush();

        self::assertSame($before + 1, $this->countSplitAuditLogs($transaction));
    }

    public function testFirstFillOfExistingTransactionIsAudited(): void
    {
        // Транзакция уже существует без категории — так её создают импорты.
        // Назначение категории позже это UPDATE, и оно обязано попасть в историю:
        // дифф скалярной колонки не содержит source строки.
        [$company, $transaction] = $this->transaction('1000.00');
        $transaction->setCashflowCategory($this->category($company, 'Аренда'));

        $this->synchronizer->sync($transaction, CashTransactionSplitSource::MANUAL);
        $this->em->flush();

        self::assertSame(1, $this->countSplitAuditLogs($transaction));
    }

    public function testNewTransactionCompositionIsNotAudited(): void
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->withOwner($owner)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->persist($account);
        $this->em->flush();

        $transaction = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            CashDirection::OUTFLOW,
            '1000.00',
            'RUB',
            new \DateTimeImmutable('2026-01-15'),
        );
        $transaction->setCashflowCategory($this->category($company, 'Аренда'));

        // Синхронизация до persist — так это делает CashTransactionService::add().
        $this->synchronizer->sync($transaction, CashTransactionSplitSource::MANUAL);
        $this->em->persist($transaction);
        $this->em->flush();

        self::assertSame(
            0,
            $this->countSplitAuditLogs($transaction),
            'Состав новой транзакции покрыт её CREATE-записью.',
        );
    }

    private function countSplitAuditLogs(CashTransaction $transaction): int
    {
        $logs = $this->em->getRepository(AuditLog::class)->findBy([
            'entityClass' => CashTransaction::class,
            'entityId' => (string) $transaction->getId(),
        ]);

        return count(array_filter(
            $logs,
            static fn (AuditLog $log): bool => isset($log->getDiff()['splits']),
        ));
    }

    /**
     * @return array{0: Company, 1: CashTransaction}
     */
    private function transaction(string $amount): array
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->build();
        $company = CompanyBuilder::aCompany()
            ->withId(Uuid::uuid4()->toString())
            ->withOwner($owner)
            ->build();
        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->persist($account);

        $transaction = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            CashDirection::OUTFLOW,
            $amount,
            'RUB',
            new \DateTimeImmutable('2026-01-15'),
        );
        $this->em->persist($transaction);
        $this->em->flush();

        return [$company, $transaction];
    }

    private function category(Company $company, string $name): CashflowCategory
    {
        $category = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        $category->setName($name);
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }
}
