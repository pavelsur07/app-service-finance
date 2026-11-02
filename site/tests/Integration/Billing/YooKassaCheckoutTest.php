<?php

declare(strict_types=1);

namespace App\Tests\Integration\Billing;

use App\Billing\Application\ApplyYooKassaPaymentAction;
use App\Billing\Application\DTO\RegisterPaymentCommand;
use App\Billing\Application\DTO\StartCheckoutCommand;
use App\Billing\Application\RegisterPaymentAction;
use App\Billing\Application\StartCheckoutAction;
use App\Billing\Entity\BillingCheckout;
use App\Billing\Entity\Tariff;
use App\Billing\Enum\CheckoutStatus;
use App\Billing\Enum\PaymentSource;
use App\Billing\Enum\TariffCode;
use App\Billing\Exception\OnlinePaymentNotAvailableException;
use App\Billing\Exception\YooKassaUnavailableException;
use App\Billing\Infrastructure\YooKassa\YooKassaConfig;
use App\Billing\Message\ApplyYooKassaPaymentMessage;
use App\Billing\MessageHandler\ApplyYooKassaPaymentHandler;
use App\Billing\Repository\TariffRepository;
use App\Tests\Builders\Billing\BillingCheckoutBuilder;
use App\Tests\Builders\Billing\CompanySubscriptionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Billing\YooKassaResponses;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Онлайн-оплата ЮKassa на мок-ответах API v3. «Сегодня» — 10.03.2027 по Москве.
 * Цены — справочник миграции: Старт 1 мес 4 970 ₽, 3 мес 14 160 ₽.
 */
final class YooKassaCheckoutTest extends IntegrationTestCase
{
    private const START_1_MONTH = 497000;
    private const START_3_MONTHS = 1416000;

    private YooKassaResponses $yooKassa;
    private string $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        // Часы подменяются до EntityManager (его слушатели инициализируют ClockInterface) — в новом ядре.
        self::ensureKernelShutdown();
        self::bootKernel();
        self::getContainer()->set(ClockInterface::class, new MockClock(new \DateTimeImmutable('2027-03-10 10:00:00', new \DateTimeZone('Europe/Moscow'))));
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->em->getConnection();
        $this->yooKassa = YooKassaResponses::install(self::getContainer()->get('billing.yookassa.http_client'));
        $this->companyId = $this->company();
    }

    public function testStartCreatesPaymentAndRepeatedFormReturnsSameLinkWithoutSecondPayment(): void
    {
        $checkoutId = Uuid::uuid7()->toString();
        $this->yooKassa->then(YooKassaResponses::payment('pending', self::START_3_MONTHS, $this->metadata($checkoutId)));

        $url = $this->start($checkoutId, TariffCode::START, 3);
        $again = $this->start($checkoutId, TariffCode::START, 3);

        self::assertStringStartsWith('https://yoomoney.ru/checkout/', $url);
        self::assertSame($url, $again);
        self::assertCount(1, $this->yooKassa->requests, 'повтор формы не создаёт второй платёж');
        $body = json_decode((string) $this->yooKassa->requests[0]['options']['body'], true);
        self::assertIsArray($body);
        self::assertSame('14160.00', $body['amount']['value'], 'сумма — из справочника, не из формы');
        self::assertSame($this->metadata($checkoutId), $body['metadata']);

        self::assertSame(
            ['status' => 'pending', 'provider_payment_id' => YooKassaResponses::PAYMENT_ID, 'amount_kopecks' => self::START_3_MONTHS, 'months' => 3],
            $this->connection->fetchAssociative('SELECT status, provider_payment_id, amount_kopecks, months FROM billing_checkouts WHERE id = ?', [$checkoutId]),
        );
    }

    public function testYooKassaOutageLeavesCheckoutWithoutLinkAndRetryUsesSameIdempotenceKey(): void
    {
        $checkoutId = Uuid::uuid7()->toString();
        $this->yooKassa
            ->then(YooKassaResponses::error(503))
            ->then(YooKassaResponses::payment('pending', self::START_1_MONTH, $this->metadata($checkoutId)));

        try {
            $this->start($checkoutId, TariffCode::START, 1);
            self::fail('ожидался отказ');
        } catch (OnlinePaymentNotAvailableException $exception) {
            self::assertStringContainsString('не отвечает', $exception->getMessage());
        }
        self::assertSame(
            ['status' => 'pending', 'provider_payment_id' => null],
            $this->connection->fetchAssociative('SELECT status, provider_payment_id FROM billing_checkouts WHERE id = ?', [$checkoutId]),
        );

        $this->start($checkoutId, TariffCode::START, 1);

        self::assertCount(2, $this->yooKassa->requests);
        foreach ($this->yooKassa->requests as $request) {
            self::assertContains('Idempotence-Key: '.$checkoutId, $request['options']['headers']);
        }
        self::assertSame(YooKassaResponses::PAYMENT_ID, $this->connection->fetchOne('SELECT provider_payment_id FROM billing_checkouts WHERE id = ?', [$checkoutId]));
    }

    public function testPerpetualTariffAndTariffChangeDuringPaidPeriodAreRefusedBeforeAnyPayment(): void
    {
        $this->em->persist(CompanySubscriptionBuilder::aSubscription()->withId(Uuid::uuid7()->toString())->forCompany($this->companyId)->onTariff($this->tariff(TariffCode::FRIEND))->perpetualFrom('2026-11-01')->build());
        $this->em->flush();
        $this->assertRefused(fn () => $this->start(Uuid::uuid7()->toString(), TariffCode::START, 1), 'бессрочный');

        $paidCompany = $this->company();
        $this->em->persist(CompanySubscriptionBuilder::aSubscription()->withId(Uuid::uuid7()->toString())->forCompany($paidCompany)->onTariff($this->tariff(TariffCode::START))->paid('2027-03-01', '2027-03-31')->build());
        $this->em->flush();
        $this->assertRefused(fn () => $this->start(Uuid::uuid7()->toString(), TariffCode::BASE, 1, $paidCompany), 'только тариф «Старт»');
        $this->assertRefused(fn () => $this->start(Uuid::uuid7()->toString(), TariffCode::SUPPORT, 1), 'нельзя оплатить онлайн');
        $this->assertRefused(fn () => $this->start(Uuid::uuid7()->toString(), TariffCode::START, 2), 'нет цены');

        self::assertSame([], $this->yooKassa->requests);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM billing_checkouts'));
    }

    public function testDisabledWithoutKeys(): void
    {
        self::getContainer()->set(YooKassaConfig::class, new YooKassaConfig('', ''));

        $this->assertRefused(fn () => $this->start(Uuid::uuid7()->toString(), TariffCode::START, 1), 'не подключена');
        self::assertSame([], $this->yooKassa->requests);
    }

    public function testSucceededPaymentIsRegisteredOnceAndRepeatDoesNothing(): void
    {
        $checkout = $this->checkout(TariffCode::START, 3, self::START_3_MONTHS);
        $this->yooKassa->then(YooKassaResponses::payment('succeeded', self::START_3_MONTHS, $this->metadata($checkout->getId())));

        self::assertSame(CheckoutStatus::SUCCEEDED, $this->apply());
        self::assertSame(CheckoutStatus::SUCCEEDED, $this->apply(), 'повтор уведомления');

        self::assertCount(1, $this->yooKassa->requests, 'завершённую попытку в ЮKassa не перепроверяем');
        self::assertSame(
            [['id' => $checkout->getId(), 'source' => 'yookassa', 'external_id' => YooKassaResponses::PAYMENT_ID, 'amount_kopecks' => self::START_3_MONTHS, 'received_at' => '2027-03-10 10:05:00']],
            $this->connection->fetchAllAssociative('SELECT id, source, external_id, amount_kopecks, received_at FROM billing_payments WHERE company_id = ?', [$this->companyId]),
        );
        self::assertSame(
            ['paid_from' => '2027-03-10', 'paid_until' => '2027-06-09'],
            $this->connection->fetchAssociative('SELECT paid_from, paid_until FROM billing_company_subscriptions WHERE company_id = ?', [$this->companyId]),
        );
        self::assertSame('succeeded', $this->checkoutStatus($checkout));
    }

    public function testCrashAfterRegistrationIsCompletedByNextCheckWithoutDuplicate(): void
    {
        $checkout = $this->checkout(TariffCode::START, 1, self::START_1_MONTH);
        // Фаза 1 прошла, отметка попытки — нет (воркер упал между фазами).
        self::getContainer()->get(RegisterPaymentAction::class)(new RegisterPaymentCommand(
            $checkout->getId(), $this->companyId, TariffCode::START, 1, self::START_1_MONTH,
            new \DateTimeImmutable('2027-03-10 10:05:00'), PaymentSource::YOOKASSA, YooKassaResponses::PAYMENT_ID,
        ));
        $this->yooKassa->then(YooKassaResponses::payment('succeeded', self::START_1_MONTH, $this->metadata($checkout->getId())));

        self::assertSame(CheckoutStatus::SUCCEEDED, $this->apply());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM billing_payments WHERE company_id = ?', [$this->companyId]));
    }

    public function testCanceledPaymentMarksCheckoutCanceledAndRegistersNothing(): void
    {
        $checkout = $this->checkout(TariffCode::START, 1, self::START_1_MONTH);
        $this->yooKassa->then(YooKassaResponses::payment('canceled', self::START_1_MONTH, $this->metadata($checkout->getId())));

        self::assertSame(CheckoutStatus::CANCELED, $this->apply());
        self::assertSame('canceled', $this->checkoutStatus($checkout));
        self::assertSame(0, $this->paymentCount());
    }

    public function testForgedNotificationDoesNotRegister(): void
    {
        $checkout = $this->checkout(TariffCode::START, 1, self::START_1_MONTH);
        // ЮKassa говорит «ещё не оплачен» — уведомлению о succeeded не верим.
        $this->yooKassa->then(YooKassaResponses::payment('pending', self::START_1_MONTH, $this->metadata($checkout->getId())));
        self::assertSame(CheckoutStatus::PENDING, $this->apply());

        // Платёж чужой попытки (metadata не совпадает).
        $this->yooKassa->then(YooKassaResponses::payment('succeeded', self::START_1_MONTH, $this->metadata(Uuid::uuid7()->toString())));
        self::assertSame(CheckoutStatus::PENDING, $this->apply());

        // Платежа нет в наших попытках — в ЮKassa даже не ходим.
        self::assertNull(self::getContainer()->get(ApplyYooKassaPaymentAction::class)('3f000000-000f-5000-9000-000000000000'));

        self::assertCount(2, $this->yooKassa->requests);
        self::assertSame(0, $this->paymentCount());
        self::assertSame('pending', $this->checkoutStatus($checkout));
    }

    public function testAmountMismatchIsNotRegisteredAndLeftForAdmin(): void
    {
        $checkout = $this->checkout(TariffCode::START, 3, self::START_3_MONTHS);
        $this->yooKassa->then(YooKassaResponses::payment('succeeded', self::START_1_MONTH, $this->metadata($checkout->getId())));

        self::assertSame(CheckoutStatus::FAILED_TO_APPLY, $this->apply());

        self::assertSame(0, $this->paymentCount());
        self::assertSame(
            ['status' => 'failed_to_apply', 'failure_reason' => 'ЮKassa: 497000 RUB, ожидалось 1416000 RUB'],
            $this->connection->fetchAssociative('SELECT status, failure_reason FROM billing_checkouts WHERE id = ?', [$checkout->getId()]),
        );
    }

    public function testDomainRefusalAfterMoneyChargedMarksFailedToApply(): void
    {
        $checkout = $this->checkout(TariffCode::START, 1, self::START_1_MONTH);
        // Пока клиент платил, администратор назначил «Друга».
        $this->em->persist(CompanySubscriptionBuilder::aSubscription()->withId(Uuid::uuid7()->toString())->forCompany($this->companyId)->onTariff($this->tariff(TariffCode::FRIEND))->perpetualFrom('2026-11-01')->build());
        $this->em->flush();
        $this->yooKassa->then(YooKassaResponses::payment('succeeded', self::START_1_MONTH, $this->metadata($checkout->getId())));

        self::assertSame(CheckoutStatus::FAILED_TO_APPLY, $this->apply());

        self::assertSame(0, $this->paymentCount());
        self::assertSame('failed_to_apply', $this->checkoutStatus($checkout));
    }

    public function testOutageOnCheckIsRetryable(): void
    {
        $checkout = $this->checkout(TariffCode::START, 1, self::START_1_MONTH);
        $this->yooKassa->then(YooKassaResponses::error(500));

        try {
            $this->apply();
            self::fail('ожидался YooKassaUnavailableException');
        } catch (YooKassaUnavailableException) {
        }
        self::assertSame('pending', $this->checkoutStatus($checkout));
    }

    public function testSyncCommandQueuesOnlyPendingCheckoutsInWindow(): void
    {
        $tariff = $this->tariff(TariffCode::START);
        $due = BillingCheckoutBuilder::aCheckout()->withId(Uuid::uuid7()->toString())->forCompany($this->companyId)->onTariff($tariff, 1, self::START_1_MONTH)->createdAt('2027-03-10 09:00:00')->build();
        $fresh = BillingCheckoutBuilder::aCheckout()->withId(Uuid::uuid7()->toString())->forCompany($this->companyId)->onTariff($tariff, 1, self::START_1_MONTH)->withProviderPayment('2f8a6b3c-000f-5000-9000-000000000002')->createdAt('2027-03-10 09:58:00')->build();
        $old = BillingCheckoutBuilder::aCheckout()->withId(Uuid::uuid7()->toString())->forCompany($this->companyId)->onTariff($tariff, 1, self::START_1_MONTH)->withProviderPayment('2f8a6b3c-000f-5000-9000-000000000003')->createdAt('2027-03-05 09:00:00')->build();
        $noLink = BillingCheckoutBuilder::aCheckout()->withId(Uuid::uuid7()->toString())->forCompany($this->companyId)->onTariff($tariff, 1, self::START_1_MONTH)->withProviderPayment(null)->createdAt('2027-03-10 09:00:00')->build();
        foreach ([$due, $fresh, $old, $noLink] as $checkout) {
            $this->em->persist($checkout);
        }
        $this->em->flush();

        self::assertNotNull(self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find('app:billing:yookassa-sync'));
        self::assertSame(0, $tester->execute([]));

        $transport = self::getContainer()->get('messenger.transport.async_sync');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $queued = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(ApplyYooKassaPaymentMessage::class, $message);
            $queued[] = $message->providerPaymentId;
        }
        self::assertSame([YooKassaResponses::PAYMENT_ID], $queued);
        self::assertSame([], $this->yooKassa->requests, 'команда сама в ЮKassa не ходит');
    }

    public function testHandlerRetriesOnOutageAndAppliesOnSuccess(): void
    {
        $checkout = $this->checkout(TariffCode::START, 1, self::START_1_MONTH);
        $handler = self::getContainer()->get(ApplyYooKassaPaymentHandler::class);
        $this->yooKassa->then(YooKassaResponses::error(502));

        try {
            $handler(new ApplyYooKassaPaymentMessage(YooKassaResponses::PAYMENT_ID));
            self::fail('ожидался RecoverableMessageHandlingException');
        } catch (RecoverableMessageHandlingException) {
        }

        $this->yooKassa->then(YooKassaResponses::error(401, 'invalid_credentials'));
        try {
            $handler(new ApplyYooKassaPaymentMessage(YooKassaResponses::PAYMENT_ID));
            self::fail('ожидался UnrecoverableMessageHandlingException');
        } catch (UnrecoverableMessageHandlingException) {
        }

        $this->yooKassa->then(YooKassaResponses::payment('succeeded', self::START_1_MONTH, $this->metadata($checkout->getId())));
        $handler(new ApplyYooKassaPaymentMessage(YooKassaResponses::PAYMENT_ID));
        self::assertSame('succeeded', $this->checkoutStatus($checkout));
    }

    private function start(string $checkoutId, TariffCode $tariff, int $months, ?string $companyId = null): string
    {
        return self::getContainer()->get(StartCheckoutAction::class)(new StartCheckoutCommand(
            $checkoutId,
            $companyId ?? $this->companyId,
            $tariff,
            $months,
            'https://app.test/company/price?checkout='.$checkoutId,
            'owner@example.test',
        ));
    }

    private function apply(): ?CheckoutStatus
    {
        return self::getContainer()->get(ApplyYooKassaPaymentAction::class)(YooKassaResponses::PAYMENT_ID);
    }

    private function checkout(TariffCode $tariff, int $months, int $amount): BillingCheckout
    {
        $checkout = BillingCheckoutBuilder::aCheckout()->withId(Uuid::uuid7()->toString())->forCompany($this->companyId)->onTariff($this->tariff($tariff), $months, $amount)->build();
        $this->em->persist($checkout);
        $this->em->flush();

        return $checkout;
    }

    /**
     * @return array<string, string>
     */
    private function metadata(string $checkoutId): array
    {
        return ['checkout_id' => $checkoutId, 'company_id' => $this->companyId];
    }

    private function assertRefused(\Closure $call, string $message): void
    {
        try {
            $call();
            self::fail('ожидался отказ: '.$message);
        } catch (OnlinePaymentNotAvailableException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function checkoutStatus(BillingCheckout $checkout): string
    {
        return (string) $this->connection->fetchOne('SELECT status FROM billing_checkouts WHERE id = ?', [$checkout->getId()]);
    }

    private function paymentCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM billing_payments WHERE company_id = ?', [$this->companyId]);
    }

    private function tariff(TariffCode $code): Tariff
    {
        $tariff = self::getContainer()->get(TariffRepository::class)->findOneByCode($code);
        self::assertNotNull($tariff);

        return $tariff;
    }

    private function company(): string
    {
        $companyId = Uuid::uuid7()->toString();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7()->toString().'@example.test')->build();
        $this->em->persist($owner);
        $this->em->persist(CompanyBuilder::aCompany()->withId($companyId)->withOwner($owner)->build());
        $this->em->flush();

        return $companyId;
    }
}
