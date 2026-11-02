<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Billing\Application\DTO\StartCheckoutCommand;
use App\Billing\Entity\BillingCheckout;
use App\Billing\Entity\Tariff;
use App\Billing\Exception\OnlinePaymentNotAvailableException;
use App\Billing\Exception\TariffChangeNotSupportedException;
use App\Billing\Exception\YooKassaRequestFailedException;
use App\Billing\Exception\YooKassaUnavailableException;
use App\Billing\Infrastructure\YooKassa\CreatePaymentRequest;
use App\Billing\Infrastructure\YooKassa\YooKassaClient;
use App\Billing\Infrastructure\YooKassa\YooKassaConfig;
use App\Billing\Repository\BillingCheckoutRepository;
use App\Billing\Repository\CompanySubscriptionRepository;
use App\Billing\Repository\TariffPriceRepository;
use App\Billing\Repository\TariffRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Webmozart\Assert\Assert;

/**
 * Начинает онлайн-оплату тарифа: попытка оплаты → платёж в ЮKassa → ссылка на страницу оплаты.
 *
 * Точка входа, сама коммитит. Фазы: (1) запись попытки — `flush`; (2) создание платежа в ЮKassa
 * вне транзакции, `Idempotence-Key` = ID попытки; (3) привязка платежа и ссылки — `flush`.
 * Упала фаза 2 или 3 — попытка остаётся `pending` без ссылки, повтор той же формы проходит
 * фазы 2–3 заново, ЮKassa по тому же ключу вернёт тот же платёж. Деньги не списаны, пока клиент
 * не перешёл по ссылке, поэтому зависшая попытка без ссылки вреда не несёт.
 */
final class StartCheckoutAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly YooKassaConfig $config,
        private readonly YooKassaClient $client,
        private readonly TariffRepository $tariffs,
        private readonly TariffPriceRepository $prices,
        private readonly CompanySubscriptionRepository $subscriptions,
        private readonly BillingCheckoutRepository $checkouts,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return string ссылка на страницу оплаты ЮKassa
     *
     * @throws OnlinePaymentNotAvailableException оплата недоступна или ЮKassa не приняла платёж
     */
    public function __invoke(StartCheckoutCommand $command): string
    {
        Assert::uuid($command->checkoutId);
        Assert::uuid($command->companyId);

        if (!$this->config->isEnabled()) {
            throw new OnlinePaymentNotAvailableException('Онлайн-оплата пока не подключена — оплатите по счёту.');
        }

        $checkout = $this->checkouts->findOneByIdAndCompanyId($command->checkoutId, $command->companyId);
        if (null !== $checkout) {
            if ($checkout->getStatus()->isFinal()) {
                throw new OnlinePaymentNotAvailableException('Эта оплата уже завершена. Обновите страницу, чтобы начать новую.');
            }
            if (null !== $checkout->getConfirmationUrl()) {
                return $checkout->getConfirmationUrl();
            }
        } else {
            $checkout = $this->createCheckout($command);
        }

        try {
            $payment = $this->client->createPayment(new CreatePaymentRequest(
                $checkout->getId(),
                $checkout->getAmountKopecks(),
                sprintf('Ваш Финдир: тариф «%s», %d мес.', $checkout->getTariff()->getName(), $checkout->getMonths()),
                $command->returnUrl,
                ['checkout_id' => $checkout->getId(), 'company_id' => $checkout->getCompanyId()],
                $command->customerEmail,
            ));
        } catch (YooKassaUnavailableException) {
            throw new OnlinePaymentNotAvailableException('ЮKassa сейчас не отвечает. Попробуйте ещё раз через минуту.');
        } catch (YooKassaRequestFailedException) {
            throw new OnlinePaymentNotAvailableException('ЮKassa не приняла платёж. Попробуйте позже или оплатите по счёту.');
        }

        if (null === $payment->confirmationUrl || !str_starts_with($payment->confirmationUrl, 'https://')) {
            $this->logger->error('Billing: ЮKassa вернула платёж без ссылки на оплату', [
                'companyId' => $checkout->getCompanyId(),
                'checkoutId' => $checkout->getId(),
                'paymentStatus' => $payment->status,
            ]);

            throw new OnlinePaymentNotAvailableException('ЮKassa не вернула ссылку на оплату. Попробуйте позже или оплатите по счёту.');
        }

        $checkout->attachProviderPayment($payment->id, $payment->confirmationUrl, $this->clock->now());
        $this->entityManager->flush();

        $this->logger->info('Billing: онлайн-оплата начата', [
            'companyId' => $checkout->getCompanyId(),
            'checkoutId' => $checkout->getId(),
            'tariff' => $checkout->getTariff()->getCode()->value,
            'months' => $checkout->getMonths(),
        ]);

        return $payment->confirmationUrl;
    }

    private function createCheckout(StartCheckoutCommand $command): BillingCheckout
    {
        $tariff = $this->tariffs->findOneByCode($command->tariffCode);
        if (null === $tariff || !$tariff->isActive() || !$tariff->isPublic() || $tariff->isPerpetual()) {
            throw new OnlinePaymentNotAvailableException('Этот тариф нельзя оплатить онлайн.');
        }
        $amount = $this->prices->amountsByTariffCode()[$tariff->getCode()->value][$command->months] ?? null;
        if (null === $amount || $amount <= 0) {
            throw new OnlinePaymentNotAvailableException('Для этого срока нет цены — выберите другой срок.');
        }

        $now = $this->clock->now();
        $this->assertRenewalAllowed($command->companyId, $tariff, $now);

        $checkout = new BillingCheckout($command->checkoutId, $command->companyId, $tariff, $command->months, $amount, $now);
        $this->entityManager->persist($checkout);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Параллельная отправка той же формы: строка уже есть, EntityManager закрыт.
            throw new OnlinePaymentNotAvailableException('Оплата уже создаётся — обновите страницу через несколько секунд.');
        }

        return $checkout;
    }

    /**
     * Те же правила, что у зачисления (`RegisterPaymentAction`), — до того, как клиент заплатит.
     */
    private function assertRenewalAllowed(string $companyId, Tariff $tariff, \DateTimeImmutable $now): void
    {
        $subscription = $this->subscriptions->findOneByCompanyId($companyId);
        if (null === $subscription) {
            return;
        }
        if ($subscription->getTariff()->isPerpetual()) {
            throw new OnlinePaymentNotAvailableException(sprintf('У кабинета бессрочный тариф «%s» — онлайн-оплата не нужна.', $subscription->getTariff()->getName()));
        }
        try {
            $subscription->renewalBase($tariff, $now);
        } catch (TariffChangeNotSupportedException) {
            throw new OnlinePaymentNotAvailableException(sprintf('Пока действует оплаченный период, онлайн можно продлить только тариф «%s». Сменить тариф — напишите нам.', $subscription->getTariff()->getName()));
        }
    }
}
