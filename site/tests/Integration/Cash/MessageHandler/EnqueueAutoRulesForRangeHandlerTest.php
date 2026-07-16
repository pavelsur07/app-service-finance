<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\MessageHandler;

use App\Cash\Message\ApplyAutoRulesForTransaction;
use App\Cash\Message\EnqueueAutoRulesForRange;
use App\Cash\MessageHandler\EnqueueAutoRulesForRangeHandler;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class EnqueueAutoRulesForRangeHandlerTest extends IntegrationTestCase
{
    public function testEnqueuesOnlyActiveTransactionsFromOpenPeriod(): void
    {
        $user = UserBuilder::aUser()->withIndex(1)->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $company->setFinanceLockBefore(new \DateTimeImmutable('2024-01-15'));
        $account = MoneyAccountBuilder::aMoneyAccount()->forCompany($company)->build();

        $locked = CashTransactionBuilder::aCashTransaction()
            ->forCompany($company)
            ->withMoneyAccount($account)
            ->build();
        $locked->setOccurredAt(new \DateTimeImmutable('2024-01-14'));

        $boundary = CashTransactionBuilder::aCashTransaction()
            ->forCompany($company)
            ->withMoneyAccount($account)
            ->build();
        $boundary->setOccurredAt(new \DateTimeImmutable('2024-01-15'));

        $open = CashTransactionBuilder::aCashTransaction()
            ->forCompany($company)
            ->withMoneyAccount($account)
            ->build();
        $open->setOccurredAt(new \DateTimeImmutable('2024-01-16'));

        $deleted = CashTransactionBuilder::aCashTransaction()
            ->forCompany($company)
            ->withMoneyAccount($account)
            ->build();
        $deleted->setOccurredAt(new \DateTimeImmutable('2024-01-16'));
        $deleted->markDeleted(null);

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($account);
        $this->em->persist($locked);
        $this->em->persist($boundary);
        $this->em->persist($open);
        $this->em->persist($deleted);
        $this->em->flush();

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            },
        );

        $handler = new EnqueueAutoRulesForRangeHandler(
            $bus,
            self::getContainer()->get(CashTransactionRepository::class),
            $this->em,
            new NullLogger(),
        );

        $handler(new EnqueueAutoRulesForRange(
            (string) $company->getId(),
            new \DateTimeImmutable('2024-01-01'),
            new \DateTimeImmutable('2024-01-31'),
        ));

        self::assertCount(2, $dispatched);
        self::assertInstanceOf(ApplyAutoRulesForTransaction::class, $dispatched[0]);
        self::assertSame($boundary->getId(), $dispatched[0]->transactionId);
        self::assertSame($open->getId(), $dispatched[1]->transactionId);
        $correlationId = $dispatched[0]->correlationId;
        self::assertIsString($correlationId);
        self::assertTrue(Uuid::isValid($correlationId));
        self::assertSame([$correlationId, $correlationId], array_column($dispatched, 'correlationId'));
    }
}
