<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Entity\Transfer;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transfer\CashTransfer;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class CashTransferTest extends TestCase
{
    public function testStoresLegsAndManualEffectiveRateMetadata(): void
    {
        $company = $this->company();
        $date = new \DateTimeImmutable('2026-08-09');
        $source = $this->leg($company, 'RUB account', 'RUB', CashDirection::OUTFLOW, '9500.00', $date);
        $target = $this->leg($company, 'USD account', 'USD', CashDirection::INFLOW, '100.00', $date);

        $transfer = new CashTransfer(
            Uuid::uuid4()->toString(),
            $company,
            $source,
            $target,
            'transfer-request-1',
            '0.010526315789473684',
            'RUB',
            'USD',
            $date,
            CashTransfer::RATE_SOURCE_MANUAL_EFFECTIVE,
        );

        self::assertSame($company, $transfer->getCompany());
        self::assertSame($source, $transfer->getSourceTransaction());
        self::assertSame($target, $transfer->getTargetTransaction());
        self::assertSame('transfer-request-1', $transfer->getIdempotencyKey());
        self::assertSame('0.010526315789473684', $transfer->getEffectiveRate());
        self::assertSame('RUB', $transfer->getRateBaseCurrency());
        self::assertSame('USD', $transfer->getRateQuoteCurrency());
        self::assertSame($date, $transfer->getRateDate());
        self::assertSame(CashTransfer::RATE_SOURCE_MANUAL_EFFECTIVE, $transfer->getRateSource());
        self::assertFalse($transfer->isDeleted());
    }

    public function testAcceptsSameCurrencyTransferWithoutFxMetadata(): void
    {
        $company = $this->company();
        $date = new \DateTimeImmutable('2026-08-09');

        $transfer = new CashTransfer(
            Uuid::uuid4()->toString(),
            $company,
            $this->leg($company, 'Source RUB', 'RUB', CashDirection::OUTFLOW, '100.00', $date),
            $this->leg($company, 'Target RUB', 'RUB', CashDirection::INFLOW, '100.00', $date),
            'transfer-request-2',
        );

        self::assertNull($transfer->getEffectiveRate());
        self::assertNull($transfer->getRateBaseCurrency());
        self::assertNull($transfer->getRateQuoteCurrency());
        self::assertNull($transfer->getRateDate());
        self::assertNull($transfer->getRateSource());
    }

    public function testRejectsLegFromAnotherCompany(): void
    {
        $company = $this->company();
        $foreignCompany = $this->company();
        $date = new \DateTimeImmutable('2026-08-09');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Обе ноги перевода должны принадлежать компании перевода.');

        new CashTransfer(
            Uuid::uuid4()->toString(),
            $company,
            $this->leg($company, 'Source RUB', 'RUB', CashDirection::OUTFLOW, '100.00', $date),
            $this->leg($foreignCompany, 'Foreign RUB', 'RUB', CashDirection::INFLOW, '100.00', $date),
            'transfer-request-3',
        );
    }

    private function company(): Company
    {
        return new Company(Uuid::uuid4()->toString(), new User(Uuid::uuid4()->toString()));
    }

    private function leg(
        Company $company,
        string $accountName,
        string $currency,
        CashDirection $direction,
        string $amount,
        \DateTimeImmutable $date,
    ): CashTransaction {
        $account = new MoneyAccount(
            Uuid::uuid4()->toString(),
            $company,
            MoneyAccountType::BANK,
            $accountName,
            $currency,
        );
        $transaction = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            $direction,
            $amount,
            $currency,
            $date,
        );
        $transaction->setIsTransfer(true);

        return $transaction;
    }
}
