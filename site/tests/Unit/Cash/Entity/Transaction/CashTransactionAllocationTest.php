<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Entity\Transaction;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Распределение транзакции ДДС по документам считается точно.
 *
 * Раньше остаток считался во float: у транзакции 0.03 с документами
 * 0.01 + 0.01 он был 0.009999999999999998, и распределить оставшуюся
 * копейку было нельзя.
 */
final class CashTransactionAllocationTest extends TestCase
{
    public function testLastKopeckCanBeAllocated(): void
    {
        $tx = $this->transaction('0.03', ['0.01', '0.01']);

        self::assertSame('0.01', $tx->getRemainingMoney()->toDecimalString());
        self::assertTrue($tx->canAllocate(Money::fromString('0.01', 'RUB')));
        self::assertFalse($tx->canAllocate(Money::fromString('0.02', 'RUB')));
    }

    public function testFloatBoundaryForFinanceAcceptsLastKopeck(): void
    {
        $tx = $this->transaction('0.06', ['0.02', '0.02']);

        self::assertTrue($tx->canAllocateAmount(0.02));
        $tx->assertCanAllocateAmount(0.02);
        self::assertSame(0.02, $tx->getRemainingAmount());
    }

    public function testFullyAllocatedLargeTransactionHasNoRemainder(): void
    {
        $tx = $this->transaction('999999999.99', ['333333333.33', '333333333.33', '333333333.33']);

        self::assertTrue($tx->getRemainingMoney()->isZero());
        self::assertSame('999999999.99', $tx->getAllocatedMoney()->toDecimalString());
        self::assertFalse($tx->canAllocate(Money::fromString('0.01', 'RUB')));
    }

    public function testDeletedDocumentIsNotCounted(): void
    {
        $tx = $this->transaction('100.00', ['60.00', '40.00']);
        $documents = $tx->getDocuments()->toArray();
        $documents[1]->markDeleted(null);

        self::assertSame('40.00', $tx->getRemainingMoney()->toDecimalString());
    }

    public function testExcludedDocumentIsReturnedToRemainder(): void
    {
        $tx = $this->transaction('100.00', ['60.00', '40.00']);
        $documents = $tx->getDocuments()->toArray();

        self::assertTrue($tx->canAllocate(Money::fromString('40.00', 'RUB'), $documents[1]));
        self::assertFalse($tx->canAllocate(Money::fromString('40.01', 'RUB'), $documents[1]));
    }

    public function testFloatStringFromFinanceFormDoesNotBreakAllocation(): void
    {
        // Форма Finance до сохранения кладёт float в виде '1.0E-5'; decimal(18,2) хранит его как 0.00.
        $tx = $this->transaction('100.00', ['1.0E-5', '40.00']);

        self::assertSame('60.00', $tx->getRemainingMoney()->toDecimalString());
        self::assertTrue($tx->canAllocateAmount(60.0));
    }

    public function testNonPositiveAmountIsRejected(): void
    {
        $tx = $this->transaction('100.00', []);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Сумма документа должна быть больше нуля.');

        $tx->assertCanAllocate(Money::fromMinor(0, 'RUB'));
    }

    /**
     * @param list<string> $documentAmounts
     */
    private function transaction(string $amount, array $documentAmounts): CashTransaction
    {
        $company = CompanyBuilder::aCompany()->withOwner(UserBuilder::aUser()->build())->build();
        $tx = CashTransactionBuilder::aCashTransaction()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withAmount($amount)
            ->build();

        foreach ($documentAmounts as $documentAmount) {
            $document = new Document(Uuid::uuid4()->toString(), $company);
            $operation = new DocumentOperation();
            $operation->setAmount($documentAmount);
            $document->addOperation($operation);
            $tx->addDocument($document);
        }

        return $tx;
    }
}
