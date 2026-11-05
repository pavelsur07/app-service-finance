<?php

declare(strict_types=1);

namespace App\Tests\Integration\Billing;

use App\Billing\Application\DTO\RegisterPaymentCommand;
use App\Billing\Application\RegisterPaymentAction;
use App\Billing\Application\ScheduleExpiryRemindersAction;
use App\Billing\Application\StartTrialAction;
use App\Billing\Entity\CompanySubscription;
use App\Billing\Entity\Tariff;
use App\Billing\Enum\PaymentSource;
use App\Billing\Enum\TariffCode;
use App\Billing\Message\SendBillingExpiryReminderMessage;
use App\Billing\MessageHandler\SendBillingExpiryReminderHandler;
use App\Billing\Repository\BillingExpiryReminderRepository;
use App\Billing\Repository\CompanySubscriptionRepository;
use App\Billing\Repository\TariffRepository;
use App\Company\Facade\CompanyFacade;
use App\Notification\Contract\NotificationSenderInterface;
use App\Notification\DTO\EmailMessage;
use App\Notification\DTO\NotificationContext;
use App\Notification\Service\NotificationRouter;
use App\Tests\Builders\Billing\CompanySubscriptionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Twig\Environment;

final class TrialAndRemindersTest extends IntegrationTestCase
{
    /** «Сегодня» для напоминаний: 10.03.2027 по Москве. */
    private const NOW = '2027-03-10 10:00:00';

    public function testStartTrialCreatesOneTrialSubscriptionAndDryRunWritesNothing(): void
    {
        $companyId = $this->company();
        $startTrial = self::getContainer()->get(StartTrialAction::class);

        self::assertSame(1, $startTrial($companyId, dryRun: true));
        self::assertSame(0, $this->subscriptionCount($companyId));

        self::assertSame(1, $startTrial($companyId));
        $this->em->flush();
        self::assertSame(0, $startTrial($companyId), 'подписка уже есть — повтор ничего не делает');
        $this->em->flush();

        $row = $this->connection->fetchAssociative(
            'SELECT t.code, s.is_trial, s.paid_until - s.paid_from + 1 AS days FROM billing_company_subscriptions s JOIN billing_tariffs t ON t.id = s.tariff_id WHERE s.company_id = ?',
            [$companyId],
        );
        self::assertSame(['code' => 'base', 'is_trial' => true, 'days' => 14], $row);
        self::assertSame(1, $this->subscriptionCount($companyId));
    }

    public function testPaymentDuringTrialSwitchesTariffAndKeepsTrialDays(): void
    {
        $companyId = $this->company();
        $this->em->persist(CompanySubscriptionBuilder::aSubscription()
            ->forCompany($companyId)
            ->onTariff($this->tariff(TariffCode::BASE))
            ->trial('2027-01-01', '2027-01-14')
            ->build());
        $this->em->flush();
        $this->em->clear();

        (self::getContainer()->get(RegisterPaymentAction::class))(new RegisterPaymentCommand(
            Uuid::uuid7()->toString(),
            $companyId,
            TariffCode::PRO,
            1,
            1_479_000,
            new \DateTimeImmutable('2027-01-05 12:00:00', new \DateTimeZone('Europe/Moscow')),
            PaymentSource::MANUAL_INVOICE,
        ));

        $this->em->clear();
        $subscription = $this->em->getRepository(CompanySubscription::class)->findOneBy(['companyId' => $companyId]);
        self::assertNotNull($subscription);
        self::assertSame(TariffCode::PRO, $subscription->getTariff()->getCode());
        self::assertFalse($subscription->isTrial());
        self::assertSame('2027-01-15', $subscription->getPaidFrom()->format('Y-m-d'));
        self::assertSame('2027-02-14', $subscription->getPaidUntil()?->format('Y-m-d'));
    }

    public function testRemindersQueueDueDaysOnceAndSkipPerpetualAndOffDays(): void
    {
        $paid7 = $this->companyWith(fn (string $id) => $this->paid($id, '2027-02-11', '2027-03-17'));
        $paid0 = $this->companyWith(fn (string $id) => $this->paid($id, '2027-02-11', '2027-03-10'));
        $paid5 = $this->companyWith(fn (string $id) => $this->paid($id, '2027-02-16', '2027-03-15'));
        $trial3 = $this->companyWith(fn (string $id) => $this->trial($id, '2027-02-28', '2027-03-13'));
        $trial7 = $this->companyWith(fn (string $id) => $this->trial($id, '2027-03-04', '2027-03-17'));
        $friend = $this->companyWith(fn (string $id) => CompanySubscriptionBuilder::aSubscription()->withId(Uuid::uuid7()->toString())->forCompany($id)->onTariff($this->tariff(TariffCode::FRIEND))->perpetualFrom('2026-11-01')->build());
        // Помечена владельцем к удалению: напоминание в срок, но письма нет.
        $deleting = $this->companyWith(fn (string $id) => $this->paid($id, '2027-02-11', '2027-03-17'));
        $this->connection->executeStatement('UPDATE companies SET deletion_requested_at = now() WHERE id = ?', [$deleting]);

        $transport = $this->transport();
        $result = $this->schedule()();

        self::assertSame(['due' => 3, 'queued' => 3, 'skipped' => 0, 'failed' => 0], $result);
        $sent = $this->sentReminders($transport);
        $expected = [$paid0 => [0, false], $paid7 => [7, false], $trial3 => [3, true]];
        ksort($expected);
        self::assertSame($expected, $sent);
        foreach ([$paid5, $trial7, $friend, $deleting] as $companyId) {
            self::assertArrayNotHasKey($companyId, $sent);
        }

        // Постановка ничего не застолбляет: повтор до отправки ставит снова (дубль отсеет handler).
        self::assertSame(0, $this->reminders()->countByCompanyId($paid7));
        $transport->reset();
        self::assertSame(3, $this->schedule()()['queued']);

        // Отправленные (отметка handler'а) повторный прогон пропускает.
        $now = new \DateTimeImmutable(self::NOW);
        $this->reminders()->claim($paid0, new \DateTimeImmutable('2027-03-10'), 0, $now);
        $this->reminders()->claim($paid7, new \DateTimeImmutable('2027-03-17'), 7, $now);
        $transport->reset();
        self::assertSame(['due' => 3, 'queued' => 1, 'skipped' => 2, 'failed' => 0], $this->schedule()());
        self::assertSame([$trial3 => [3, true]], $this->sentReminders($transport));
    }

    public function testHandlerSendsToOwnerAndSkipsRenewedSubscription(): void
    {
        $companyId = $this->companyWith(fn (string $id) => $this->paid($id, '2027-02-11', '2027-03-17'), 'owner-reminder@example.test');
        /** @var \ArrayObject<int, EmailMessage> $sent */
        $sent = new \ArrayObject();
        $handler = $this->handler($sent);

        $handler(new SendBillingExpiryReminderMessage($companyId, '2027-03-17', 7, false));
        self::assertCount(1, $sent);
        $email = $sent[0];
        self::assertInstanceOf(EmailMessage::class, $email);
        self::assertSame('owner-reminder@example.test', $email->to);
        self::assertSame('Оплаченный период заканчивается', $email->subject);

        // Шаблоны письма рендерятся с переменными handler'а.
        $twig = self::getContainer()->get(Environment::class);
        $html = $twig->render($email->htmlTemplate, $email->vars);
        self::assertStringContainsString('заканчивается 17.03.2027, осталось дней: 8', $html);
        self::assertStringContainsString('«Тариф и оплата»', $twig->render((string) $email->textTemplate, $email->vars));

        // Повторная доставка того же сообщения (воркер упал до ACK) — второго письма нет.
        $handler(new SendBillingExpiryReminderMessage($companyId, '2027-03-17', 7, false));
        self::assertCount(1, $sent);
        self::assertSame(1, $this->reminders()->countByCompanyId($companyId));

        // Продлили — «оплачено по» другое: письмо по старой дате не уходит.
        $handler(new SendBillingExpiryReminderMessage($companyId, '2027-03-10', 0, false));
        self::assertCount(1, $sent);
    }

    public function testFailedSendReleasesMarkSoRetrySends(): void
    {
        $companyId = $this->companyWith(fn (string $id) => $this->paid($id, '2027-02-11', '2027-03-17'));
        $sent = new \ArrayObject();

        try {
            ($this->handler($sent, fail: true))(new SendBillingExpiryReminderMessage($companyId, '2027-03-17', 7, false));
            self::fail('сбой SMTP должен пробрасываться для retry');
        } catch (\RuntimeException $e) {
            self::assertSame('smtp down', $e->getMessage());
        }
        self::assertSame(0, $this->reminders()->countByCompanyId($companyId));

        ($this->handler($sent))(new SendBillingExpiryReminderMessage($companyId, '2027-03-17', 7, false));
        self::assertCount(1, $sent);
    }

    public function testTrialIsOnePerOwnerAndSkippedWhenTariffUnavailable(): void
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7()->toString().'@example.test')->build();
        $first = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $second = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($first);
        $this->em->persist($second);
        $this->em->persist(CompanySubscriptionBuilder::aSubscription()->withId(Uuid::uuid7()->toString())->forCompany((string) $first->getId())->onTariff($this->tariff(TariffCode::FRIEND))->perpetualFrom('2026-11-01')->build());
        $this->em->flush();
        $startTrial = self::getContainer()->get(StartTrialAction::class);

        self::assertSame(0, $startTrial((string) $second->getId(), dryRun: true));
        self::assertSame(0, $startTrial((string) $second->getId()));
        $this->em->flush();
        self::assertSame(0, $this->subscriptionCount((string) $second->getId()), 'у владельца уже есть кабинет с тарифом');

        // Тариф пробного периода отключён — компания создаётся, но без пробного.
        $lonely = $this->company();
        $this->connection->executeStatement("UPDATE billing_tariffs SET is_active = false WHERE code = 'base'");
        $this->em->clear();
        self::assertSame(0, $startTrial($lonely));
        $this->em->flush();
        self::assertSame(0, $this->subscriptionCount($lonely));
    }

    private function schedule(): ScheduleExpiryRemindersAction
    {
        return new ScheduleExpiryRemindersAction(
            self::getContainer()->get(CompanySubscriptionRepository::class),
            $this->reminders(),
            self::getContainer()->get(MessageBusInterface::class),
            new MockClock(new \DateTimeImmutable(self::NOW, new \DateTimeZone('Europe/Moscow'))),
            new NullLogger(),
            self::getContainer()->get(CompanyFacade::class),
        );
    }

    /**
     * @param \ArrayObject<int, EmailMessage> $sent
     */
    private function handler(\ArrayObject $sent, bool $fail = false): SendBillingExpiryReminderHandler
    {
        $sender = new class($sent, $fail) implements NotificationSenderInterface {
            /** @param \ArrayObject<int, EmailMessage> $sent */
            public function __construct(private readonly \ArrayObject $sent, private readonly bool $fail)
            {
            }

            public function supports(): string
            {
                return 'email';
            }

            public function send(object $message, NotificationContext $ctx): bool
            {
                \assert($message instanceof EmailMessage);
                if ($this->fail) {
                    throw new \RuntimeException('smtp down');
                }
                $this->sent[] = $message;

                return true;
            }
        };

        return new SendBillingExpiryReminderHandler(
            self::getContainer()->get(CompanySubscriptionRepository::class),
            $this->reminders(),
            self::getContainer()->get(CompanyFacade::class),
            new NotificationRouter([$sender]),
            new NullLogger(),
            new MockClock(new \DateTimeImmutable(self::NOW, new \DateTimeZone('Europe/Moscow'))),
        );
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async_sync');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        return $transport;
    }

    /**
     * @return array<string, array{int, bool}> companyId → [daysBefore, trial]
     */
    private function sentReminders(InMemoryTransport $transport): array
    {
        $sent = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof SendBillingExpiryReminderMessage) {
                $sent[$message->companyId] = [$message->daysBefore, $message->trial];
            }
        }
        ksort($sent);

        return $sent;
    }

    private function reminders(): BillingExpiryReminderRepository
    {
        return self::getContainer()->get(BillingExpiryReminderRepository::class);
    }

    private function company(string $email = ''): string
    {
        $companyId = Uuid::uuid7()->toString();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('' !== $email ? $email : Uuid::uuid7()->toString().'@example.test')->build();
        $this->em->persist($owner);
        $this->em->persist(CompanyBuilder::aCompany()->withId($companyId)->withOwner($owner)->build());
        $this->em->flush();

        return $companyId;
    }

    /**
     * @param \Closure(string): CompanySubscription $subscription
     */
    private function companyWith(\Closure $subscription, string $email = ''): string
    {
        $companyId = $this->company($email);
        $this->em->persist($subscription($companyId));
        $this->em->flush();

        return $companyId;
    }

    private function paid(string $companyId, string $from, string $until): CompanySubscription
    {
        return CompanySubscriptionBuilder::aSubscription()->withId(Uuid::uuid7()->toString())->forCompany($companyId)->onTariff($this->tariff(TariffCode::START))->paid($from, $until)->build();
    }

    private function trial(string $companyId, string $from, string $until): CompanySubscription
    {
        return CompanySubscriptionBuilder::aSubscription()->withId(Uuid::uuid7()->toString())->forCompany($companyId)->onTariff($this->tariff(TariffCode::BASE))->trial($from, $until)->build();
    }

    private function tariff(TariffCode $code): Tariff
    {
        $tariff = self::getContainer()->get(TariffRepository::class)->findOneByCode($code);
        self::assertNotNull($tariff);

        return $tariff;
    }

    private function subscriptionCount(string $companyId): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM billing_company_subscriptions WHERE company_id = ?', [$companyId]);
    }
}
