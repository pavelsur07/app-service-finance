<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Entity\Transaction;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionSplit;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Company\Entity\Company;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class CashTransactionSplitsTest extends TestCase
{
    public function testSingleSplitCoveringFullAmountIsAccepted(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = $this->transaction($company, '1000.00');
        $category = $this->category($company, 'Аренда');

        $transaction->replaceSplits([
            new CashTransactionSplit($transaction, $category, '1000.00', CashTransactionSplitSource::MANUAL),
        ]);

        self::assertCount(1, $transaction->getSplits());
        self::assertSame('1000.00', $transaction->getSplitsTotal());
    }

    public function testSplitsAreSplitAcrossSeveralCategories(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = $this->transaction($company, '1000.00');

        $transaction->replaceSplits([
            new CashTransactionSplit($transaction, $this->category($company, 'Аренда'), '700.00', CashTransactionSplitSource::MANUAL),
            new CashTransactionSplit($transaction, $this->category($company, 'Реклама'), '300.00', CashTransactionSplitSource::MANUAL),
        ]);

        self::assertCount(2, $transaction->getSplits());
        self::assertSame('1000.00', $transaction->getSplitsTotal());
    }

    public function testSumOfSplitsMustEqualTransactionAmount(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = $this->transaction($company, '1000.00');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('не равна сумме транзакции');

        $transaction->replaceSplits([
            new CashTransactionSplit($transaction, $this->category($company, 'Аренда'), '700.00', CashTransactionSplitSource::MANUAL),
            new CashTransactionSplit($transaction, $this->category($company, 'Реклама'), '299.99', CashTransactionSplitSource::MANUAL),
        ]);
    }

    public function testEmptySplitSetIsRejected(): void
    {
        $transaction = $this->transaction(CompanyBuilder::aCompany()->build(), '1000.00');

        $this->expectException(\DomainException::class);

        $transaction->replaceSplits([]);
    }

    public function testDuplicateCategoryIsRejected(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = $this->transaction($company, '1000.00');
        $category = $this->category($company, 'Аренда');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('повторяется');

        $transaction->replaceSplits([
            new CashTransactionSplit($transaction, $category, '600.00', CashTransactionSplitSource::MANUAL),
            new CashTransactionSplit($transaction, $category, '400.00', CashTransactionSplitSource::MANUAL),
        ]);
    }

    public function testMultiSplitIsRejectedForPlDocumentCategory(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = $this->transaction($company, '1000.00');

        $plCategory = $this->category($company, 'Поставщики');
        $plCategory->setAllowPlDocument(true);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('документах ОПиУ');

        $transaction->replaceSplits([
            new CashTransactionSplit($transaction, $plCategory, '600.00', CashTransactionSplitSource::MANUAL),
            new CashTransactionSplit($transaction, $this->category($company, 'Реклама'), '400.00', CashTransactionSplitSource::MANUAL),
        ]);
    }

    public function testSingleSplitIsAllowedForPlDocumentCategory(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = $this->transaction($company, '1000.00');

        $plCategory = $this->category($company, 'Поставщики');
        $plCategory->setAllowPlDocument(true);

        $transaction->replaceSplits([
            new CashTransactionSplit($transaction, $plCategory, '1000.00', CashTransactionSplitSource::MANUAL),
        ]);

        self::assertCount(1, $transaction->getSplits());
    }

    public function testNonPositiveSplitAmountIsRejected(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = $this->transaction($company, '1000.00');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('больше нуля');

        new CashTransactionSplit($transaction, $this->category($company, 'Аренда'), '0.00', CashTransactionSplitSource::MANUAL);
    }

    public function testCategoryFromAnotherCompanyIsRejected(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = $this->transaction($company, '1000.00');
        $foreignCompany = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->build();
        $foreignCategory = $this->category($foreignCompany, 'Чужая');

        $this->expectException(\InvalidArgumentException::class);

        new CashTransactionSplit($transaction, $foreignCategory, '1000.00', CashTransactionSplitSource::MANUAL);
    }

    public function testSplitOfAnotherTransactionIsRejected(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = $this->transaction($company, '1000.00');
        $other = $this->transaction($company, '1000.00');

        $foreignSplit = new CashTransactionSplit(
            $other,
            $this->category($company, 'Аренда'),
            '1000.00',
            CashTransactionSplitSource::MANUAL,
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('другой транзакции');

        $transaction->replaceSplits([$foreignSplit]);
    }

    public function testClearSplitsMirrorsEmptyCategory(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = $this->transaction($company, '1000.00');

        $transaction->replaceSplits([
            new CashTransactionSplit($transaction, $this->category($company, 'Аренда'), '1000.00', CashTransactionSplitSource::MANUAL),
        ]);
        $transaction->clearSplits();

        self::assertCount(0, $transaction->getSplits());
        self::assertSame('0', $transaction->getSplitsTotal());
    }

    public function testAuditSnapshotIsStableAcrossOrder(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $first = $this->transaction($company, '1000.00');
        $second = $this->transaction($company, '1000.00');

        $rent = $this->category($company, 'Аренда');
        $ads = $this->category($company, 'Реклама');

        $first->replaceSplits([
            new CashTransactionSplit($first, $rent, '700.00', CashTransactionSplitSource::MANUAL),
            new CashTransactionSplit($first, $ads, '300.00', CashTransactionSplitSource::MANUAL),
        ]);
        $second->replaceSplits([
            new CashTransactionSplit($second, $ads, '300.00', CashTransactionSplitSource::MANUAL),
            new CashTransactionSplit($second, $rent, '700.00', CashTransactionSplitSource::MANUAL),
        ]);

        self::assertSame($first->splitsAuditSnapshot(), $second->splitsAuditSnapshot());
    }

    public function testAmountWithMoreThanTwoDecimalsIsRejected(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = $this->transaction($company, '1.99');

        // bcmath со scale 2 усёк бы «1.999» до «1.99» и пропустил инвариант,
        // а PostgreSQL NUMERIC(18,2) округлил бы до «2.00» уже после flush.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('двумя знаками');

        new CashTransactionSplit($transaction, $this->category($company, 'Аренда'), '1.999', CashTransactionSplitSource::MANUAL);
    }

    public function testAmountWithTrailingPrecisionBeyondScaleIsRejected(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = $this->transaction($company, '1.00');

        // Сравнение на фиксированном scale такое значение пропускало бы.
        $this->expectException(\DomainException::class);

        new CashTransactionSplit($transaction, $this->category($company, 'Аренда'), '1.0000001', CashTransactionSplitSource::MANUAL);
    }

    public function testSplitsCollectionIsNotMutableFromOutside(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = $this->transaction($company, '1000.00');
        $transaction->replaceSplits([
            new CashTransactionSplit($transaction, $this->category($company, 'Аренда'), '1000.00', CashTransactionSplitSource::MANUAL),
        ]);

        $transaction->getSplits()->clear();

        self::assertCount(1, $transaction->getSplits(), 'Наружу должен уходить снимок, а не живая коллекция.');
    }

    private function transaction(Company $company, string $amount): CashTransaction
    {
        return CashTransactionBuilder::aCashTransaction()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withAmount($amount)
            ->build();
    }

    private function category(Company $company, string $name): CashflowCategory
    {
        return CashflowCategoryBuilder::aCashflowCategory()
            ->withId(Uuid::uuid4()->toString())
            ->withCompany($company)
            ->withName($name)
            ->build();
    }
}
