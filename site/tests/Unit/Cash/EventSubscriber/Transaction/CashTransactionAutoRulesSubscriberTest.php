<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\EventSubscriber\Transaction;

use App\Cash\Application\Service\AutoRuleDispatchGuard;
use App\Cash\Application\Service\DebouncedRangeEnqueuer;
use App\Cash\EventSubscriber\Transaction\CashTransactionAutoRulesSubscriber;
use App\Cash\Message\ApplyAutoRulesForTransaction;
use App\Cash\Message\EnqueueAutoRulesForRange;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

final class CashTransactionAutoRulesSubscriberTest extends TestCase
{
    public function testPostPersistEnqueuesDelayedRangesWithDistinctCorrelations(): void
    {
        $transaction = CashTransactionBuilder::aCashTransaction()->build();
        $secondTransaction = CashTransactionBuilder::aCashTransaction()->build();
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLock')->willReturn($lock);

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static function (object $message, array $stamps) use (&$dispatched): Envelope {
                $dispatched[] = [$message, $stamps];

                return new Envelope($message, $stamps);
            },
        );
        $subscriber = $this->createSubscriber($bus, $lockFactory);

        $subscriber->postPersist(new LifecycleEventArgs($transaction, $entityManager));
        $subscriber->postPersist(new LifecycleEventArgs($secondTransaction, $entityManager));

        self::assertCount(2, $dispatched);
        self::assertInstanceOf(EnqueueAutoRulesForRange::class, $dispatched[0][0]);
        self::assertInstanceOf(DelayStamp::class, $dispatched[0][1][0]);
        self::assertSame(10000, $dispatched[0][1][0]->getDelay());
        self::assertTrue(Uuid::isValid((string) $dispatched[0][0]->correlationId));
        self::assertTrue(Uuid::isValid((string) $dispatched[1][0]->correlationId));
        self::assertNotSame($dispatched[0][0]->correlationId, $dispatched[1][0]->correlationId);
    }

    public function testPostUpdateIgnoresOutputOnlyChanges(): void
    {
        $transaction = CashTransactionBuilder::aCashTransaction()->build();
        $args = $this->createPostUpdateArgs($transaction, ['cashflowCategory' => [null, 'category']]);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $subscriber = $this->createSubscriber($bus, $this->createMock(LockFactory::class));

        $subscriber->postUpdate($args);
    }

    public function testDuplicateRangeEventEnqueuesTransactionFallback(): void
    {
        $transaction = CashTransactionBuilder::aCashTransaction()->build();
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(false);
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLock')->willReturn($lock);

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static function (object $message, array $stamps) use (&$dispatched): Envelope {
                $dispatched[] = [$message, $stamps];

                return new Envelope($message, $stamps);
            },
        );

        $this->createSubscriber($bus, $lockFactory)
            ->postPersist(new LifecycleEventArgs($transaction, $entityManager));

        self::assertCount(1, $dispatched);
        self::assertInstanceOf(ApplyAutoRulesForTransaction::class, $dispatched[0][0]);
        self::assertSame($transaction->getId(), $dispatched[0][0]->transactionId);
        self::assertTrue(Uuid::isValid((string) $dispatched[0][0]->correlationId));
        self::assertInstanceOf(DelayStamp::class, $dispatched[0][1][0]);
        self::assertSame(10000, $dispatched[0][1][0]->getDelay());
    }

    public function testPostUpdateEnqueuesRangeForMatcherInputChange(): void
    {
        $transaction = CashTransactionBuilder::aCashTransaction()->build();
        $args = $this->createPostUpdateArgs($transaction, ['description' => ['old', 'new']]);

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLock')->willReturn($lock);

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static function (object $message, array $stamps) use (&$dispatched): Envelope {
                $dispatched[] = [$message, $stamps];

                return new Envelope($message, $stamps);
            },
        );
        $subscriber = $this->createSubscriber($bus, $lockFactory);

        $subscriber->postUpdate($args);

        self::assertCount(1, $dispatched);
        self::assertInstanceOf(EnqueueAutoRulesForRange::class, $dispatched[0][0]);
        self::assertSame(
            [(string) $transaction->getMoneyAccount()->getId()],
            $dispatched[0][0]->moneyAccountIds,
        );
    }

    public function testSuppressedPostUpdateDoesNotEnqueue(): void
    {
        $transaction = CashTransactionBuilder::aCashTransaction()->build();
        $args = $this->createPostUpdateArgs($transaction, ['counterparty' => [null, 'counterparty']]);
        $guard = new AutoRuleDispatchGuard();

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $subscriber = $this->createSubscriber($bus, $this->createMock(LockFactory::class), $guard);

        $guard->suppress(static fn () => $subscriber->postUpdate($args));
    }

    private function createSubscriber(
        MessageBusInterface $bus,
        LockFactory $lockFactory,
        ?AutoRuleDispatchGuard $guard = null,
    ): CashTransactionAutoRulesSubscriber {
        return new CashTransactionAutoRulesSubscriber(
            $bus,
            new DebouncedRangeEnqueuer($lockFactory),
            $guard ?? new AutoRuleDispatchGuard(),
        );
    }

    private function createPostUpdateArgs(object $entity, array $changeSet): PostUpdateEventArgs
    {
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->method('getEntityChangeSet')->willReturn($changeSet);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getUnitOfWork')->willReturn($unitOfWork);

        return new PostUpdateEventArgs($entity, $entityManager);
    }
}
