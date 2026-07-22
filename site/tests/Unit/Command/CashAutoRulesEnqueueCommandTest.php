<?php

namespace App\Tests\Unit\Command;

use App\Cash\Command\CashAutoRulesEnqueueCommand;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleApplyMode;
use App\Cash\Message\EnqueueAutoRulesForRange;
use App\Company\Repository\UserRepository;
use App\Tests\Builders\Company\UserBuilder;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class CashAutoRulesEnqueueCommandTest extends TestCase
{
    public function testExecuteAddsCorrelationId(): void
    {
        $dispatched = null;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched = $message;

                return new Envelope($message);
            },
        );

        $tester = new CommandTester(new CashAutoRulesEnqueueCommand($bus, $this->userRepository()));

        self::assertSame(Command::SUCCESS, $tester->execute([
            'companyId' => Uuid::uuid7()->toString(),
        ]));
        self::assertInstanceOf(EnqueueAutoRulesForRange::class, $dispatched);
        self::assertTrue(Uuid::isValid((string) $dispatched->correlationId));
        self::assertSame(CashTransactionAutoRuleApplyMode::SAFE, $dispatched->mode);
    }

    public function testExecuteFailsWhenAccountsContainNonUuid(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $command = new CashAutoRulesEnqueueCommand($bus, $this->userRepository());
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'companyId' => '19621cff-b028-45d9-9193-11f47ad9a8b2',
            '--accounts' => 'acc-1,acc-2',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Опция --accounts должна содержать UUID', $tester->getDisplay());
    }

    public function testExecuteFailsWhenCompanyIdIsNotUuid(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $tester = new CommandTester(new CashAutoRulesEnqueueCommand($bus, $this->userRepository()));

        $exitCode = $tester->execute(['companyId' => 'not-a-uuid']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Аргумент companyId должен содержать UUID', $tester->getDisplay());
    }

    public function testUnsafeModeRequiresExplicitConfirmationAndActor(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $tester = new CommandTester(new CashAutoRulesEnqueueCommand($bus, $this->userRepository()));

        $exitCode = $tester->execute([
            'companyId' => Uuid::uuid7()->toString(),
            '--mode' => CashTransactionAutoRuleApplyMode::REPLACE_AUTO_ASSIGNED->value,
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('--confirm-replace', $tester->getDisplay());
    }

    public function testUnsafeModePropagatesActor(): void
    {
        $dispatched = null;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched = $message;

                return new Envelope($message);
            },
        );
        $tester = new CommandTester(new CashAutoRulesEnqueueCommand($bus, $this->userRepository(true)));
        $actorId = Uuid::uuid7()->toString();

        $exitCode = $tester->execute([
            'companyId' => Uuid::uuid7()->toString(),
            '--mode' => CashTransactionAutoRuleApplyMode::REPLACE_AUTO_ASSIGNED->value,
            '--actor-user-id' => $actorId,
            '--confirm-replace' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertInstanceOf(EnqueueAutoRulesForRange::class, $dispatched);
        self::assertSame(CashTransactionAutoRuleApplyMode::REPLACE_AUTO_ASSIGNED, $dispatched->mode);
        self::assertSame($actorId, $dispatched->initiatedByUserId);
    }

    private function userRepository(bool $userExists = false): UserRepository
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findOneByIdAndCompanyId')->willReturn(
            $userExists ? UserBuilder::aUser()->build() : null,
        );

        return $repository;
    }
}
