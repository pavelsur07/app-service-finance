<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Entity\Transfer;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transfer\CashTransfer;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Repository\Transfer\CashTransferRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class CashTransferPersistenceTest extends IntegrationTestCase
{
    public function testPersistsAndFindsAggregateByCompanyIdempotencyAndLeg(): void
    {
        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $date = new \DateTimeImmutable('2026-08-09');
        $sourceAccount = new MoneyAccount(
            Uuid::uuid4()->toString(),
            $company,
            MoneyAccountType::BANK,
            'Source RUB',
            'RUB',
        );
        $targetAccount = new MoneyAccount(
            Uuid::uuid4()->toString(),
            $company,
            MoneyAccountType::BANK,
            'Target USD',
            'USD',
        );
        $source = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $sourceAccount,
            CashDirection::OUTFLOW,
            '9500.00',
            'RUB',
            $date,
        );
        $target = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $targetAccount,
            CashDirection::INFLOW,
            '100.00',
            'USD',
            $date,
        );
        $source->setIsTransfer(true);
        $target->setIsTransfer(true);
        $transfer = new CashTransfer(
            Uuid::uuid4()->toString(),
            $company,
            $source,
            $target,
            'transfer-persistence-1',
            '0.010526315789473684',
            'RUB',
            'USD',
            $date,
            CashTransfer::RATE_SOURCE_MANUAL_EFFECTIVE,
        );

        foreach ([$user, $company, $sourceAccount, $targetAccount, $source, $target, $transfer] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $transferId = $transfer->getId();
        $companyId = (string) $company->getId();
        $sourceId = $source->getId();
        $this->em->clear();

        /** @var CashTransferRepository $repository */
        $repository = self::getContainer()->get(CashTransferRepository::class);
        $byId = $repository->findOneByIdAndCompanyId($transferId, $companyId);
        $byKey = $repository->findOneByCompanyIdAndIdempotencyKey($companyId, 'transfer-persistence-1');
        $sourceReference = $this->em->getReference(CashTransaction::class, $sourceId);
        $byLeg = $repository->findOneByTransaction($sourceReference);

        self::assertNotNull($byId);
        self::assertSame($transferId, $byKey?->getId());
        self::assertSame($transferId, $byLeg?->getId());
        self::assertSame('0.010526315789473684', $byId->getEffectiveRate());
        self::assertSame('RUB', $byId->getRateBaseCurrency());
        self::assertSame('USD', $byId->getRateQuoteCurrency());
        self::assertSame(CashTransfer::RATE_SOURCE_MANUAL_EFFECTIVE, $byId->getRateSource());
    }
}
