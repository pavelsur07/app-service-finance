<?php

declare(strict_types=1);

namespace App\Tests\Integration\Telegram;

use App\Cash\Application\DTO\PaymentIntake\RequestPaymentIntakeCommand;
use App\Cash\Facade\CashFacade;
use App\Company\Facade\CompanyFacade;
use App\ServiceGateway\Application\DTO\AiCompanyBudget;
use App\ServiceGateway\Facade\AiTaskFacadeInterface;
use App\Telegram\Application\QueuePaymentIntakePollAction;
use App\Telegram\Command\RecoverPaymentIntakeDeliveryCommand;
use App\Telegram\Entity\ClientBinding;
use App\Telegram\Entity\PaymentIntakeSession;
use App\Telegram\Entity\TelegramBot;
use App\Telegram\Entity\TelegramUser;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class RecoverPaymentIntakeAllCompaniesTest extends IntegrationTestCase
{
    /** @var list<string> */
    private array $grants = [];
    /** @var list<string> */
    private array $cashChecks = [];
    /** @var list<string> */
    private array $queued = [];
    private ?string $cashFailure = null;
    private ?string $deliveryFailure = null;
    private bool $recovering = false;
    private AiTaskFacadeInterface $ai;
    private CashFacade $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $ai = $this->createStub(AiTaskFacadeInterface::class);
        $ai->method('budgetFor')->willReturn(new AiCompanyBudget(1000, 0, 10000, 0, 90));
        $ai->method('isEnabledForCompany')->willReturnCallback(function (string $companyId): bool {
            if ($this->recovering) {
                $this->cashChecks[] = $companyId;
                if ($companyId === $this->cashFailure) {
                    throw new \RuntimeException('Simulated Cash authorization channel failure');
                }
            }

            return in_array($companyId, $this->grants, true);
        });
        $ai->method('connectedCompanyIds')->willReturnCallback(fn (): array => $this->grants);
        $taskId = static fn (string $company, string $type, string $key): string => Uuid::uuid5(Uuid::NAMESPACE_URL, $company.'/'.$type.'/'.$key)->toString();
        $ai->method('taskIdFor')->willReturnCallback($taskId);
        $ai->method('requestTask')->willReturnCallback(static fn (string $company, string $type, array $payload, string $key): string => $taskId($company, $type, $key));
        self::getContainer()->set(AiTaskFacadeInterface::class, $ai);
        $this->ai = $ai;
        $this->cash = self::getContainer()->get(CashFacade::class);
    }

    public function testAllRecoveryCoversGrantsAndPendingUngrantDelivery(): void
    {
        [$a, $b, $c, $d] = $this->fixtures();
        $this->grants = [$b, $a, $b];
        $tester = $this->tester();

        self::assertSame(0, $tester->execute(['--all' => true]));
        self::assertSame([$a, $b, $c], $this->queued, 'sorted union, including existing delivery after grant revocation');
        self::assertNotContains($d, $this->cashChecks);
        self::assertFalse($this->pendingCash($a));
        self::assertFalse($this->pendingCash($b));
        self::assertTrue($this->pendingCash($c), 'revoked company gets delivery only');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM cash_transaction'));
        self::assertSame(0, $tester->execute(['--all' => true]));
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT count(*) FROM cash_payment_intake_draft'));
    }

    public function testRevokedCompanyWithoutTelegramSessionRecoversConfirmedCashEventOnly(): void
    {
        [$a, $b, $c] = $this->fixtures();
        $this->grants = [$a, $b];
        $this->connection->executeStatement("UPDATE cash_payment_intake_draft SET status = 'confirmed', dispatch_pending = false, cash_event_pending = true WHERE company_id = ?", [$c]);
        $this->connection->executeStatement('DELETE FROM telegram_payment_intake_session WHERE company_id = ?', [$c]);
        $this->em->clear();

        self::assertSame(0, $this->tester()->execute(['--all' => true]));
        self::assertFalse($this->connection->fetchOne('SELECT cash_event_pending FROM cash_payment_intake_draft WHERE company_id = ?', [$c]));
        self::assertFalse($this->pendingCash($c));
        self::assertNotContains($c, $this->queued);
    }

    public function testCashChannelFailureDoesNotBlockDeliveryOrNextCompany(): void
    {
        [$a, $b] = $this->fixtures();
        $this->grants = [$a, $b];
        $this->cashFailure = $a;

        self::assertSame(1, $this->tester()->execute(['--all' => true]));
        self::assertContains($a, $this->queued);
        self::assertContains($b, $this->queued);
        self::assertFalse($this->pendingCash($b));
    }

    public function testBrokerFailureDoesNotBlockNextCompanyAndIsRecoverable(): void
    {
        [$a, $b] = $this->fixtures();
        $this->grants = [$a, $b];
        $this->deliveryFailure = $a;
        $tester = $this->tester();

        self::assertSame(1, $tester->execute(['--all' => true]));
        self::assertNotContains($a, $this->queued);
        self::assertContains($b, $this->queued);
        $this->deliveryFailure = null;
        self::assertSame(0, $tester->execute(['--all' => true]));
        self::assertContains($a, $this->queued);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM cash_transaction'));
    }

    /** @return list<string> */
    private function fixtures(): array
    {
        $ids = [];
        for ($i = 1; $i <= 4; ++$i) {
            $id = sprintf('11111111-1111-7111-8111-%012d', $i);
            $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('recovery-'.$i.'@example.test')->build();
            $company = CompanyBuilder::aCompany()->withId($id)->withOwner($owner)->build();
            $account = MoneyAccountBuilder::aMoneyAccount()->withId(Uuid::uuid7()->toString())->forCompany($company)->build();
            $bot = new TelegramBot(Uuid::uuid7()->toString(), 'TEST-TOKEN-'.$i);
            $bot->setIsActive(true);
            $user = new TelegramUser(Uuid::uuid7()->toString(), (string) (70000 + $i));
            $binding = new ClientBinding(Uuid::uuid7()->toString(), $company, $bot, $user);
            $binding->setActorUserId((string) $owner->getId());
            $binding->setMoneyAccount($account);
            foreach ([$owner, $company, $account, $bot, $user, $binding] as $entity) {
                $this->em->persist($entity);
            }
            $this->em->flush();
            $this->grants[] = $id;
            $ids[] = $id;
            if (4 === $i) {
                continue;
            }
            $draft = $this->cash->requestPaymentIntake(new RequestPaymentIntakeCommand($id, (string) $owner->getId(), (string) $account->getId(), 'recovery:'.$id, 'Оплатил рекламу 1500', new \DateTimeImmutable('2026-11-15')));
            $session = new PaymentIntakeSession($binding, $user->getTgUserId(), '55', $draft->id);
            $this->em->persist($session);
            $this->em->flush();
        }
        // Simulate persisted Cash dispatch intents after a broker interruption.
        $this->connection->executeStatement('UPDATE cash_payment_intake_draft SET dispatch_pending = true');
        $this->em->clear();

        return $ids;
    }

    private function pendingCash(string $id): bool
    {
        return (bool) $this->connection->fetchOne('SELECT dispatch_pending FROM cash_payment_intake_draft WHERE company_id = ?', [$id]);
    }

    private function tester(): CommandTester
    {
        $this->recovering = true;
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            if ($message->companyId === $this->deliveryFailure) {
                throw new \RuntimeException('Broker unavailable');
            }
            $this->queued[] = $message->companyId;

            return new Envelope($message);
        });
        $command = new RecoverPaymentIntakeDeliveryCommand($this->em, $this->cash, self::getContainer()->get(CompanyFacade::class), new QueuePaymentIntakePollAction($this->em, $bus), new Logger('test', [new TestHandler()]), $this->ai);

        return new CommandTester($command);
    }
}
