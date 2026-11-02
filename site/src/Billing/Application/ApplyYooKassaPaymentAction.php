<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Billing\Application\DTO\RegisterPaymentCommand;
use App\Billing\Entity\BillingCheckout;
use App\Billing\Enum\CheckoutStatus;
use App\Billing\Enum\PaymentSource;
use App\Billing\Exception\InvalidBillingInputException;
use App\Billing\Exception\PerpetualTariffChangeNotConfirmedException;
use App\Billing\Exception\TariffChangeNotSupportedException;
use App\Billing\Exception\TariffNotPayableException;
use App\Billing\Exception\YooKassaRequestFailedException;
use App\Billing\Exception\YooKassaUnavailableException;
use App\Billing\Infrastructure\YooKassa\YooKassaClient;
use App\Billing\Infrastructure\YooKassa\YooKassaConfig;
use App\Billing\Infrastructure\YooKassa\YooKassaPayment;
use App\Billing\Repository\BillingCheckoutRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Применяет состояние платежа ЮKassa к попытке оплаты. Истина — `GET /payments/{id}` из ЮKassa;
 * уведомлению и параметрам возврата не доверяем, они только повод проверить.
 *
 * Точка входа, сама коммитит. Фазы: (1) зачисление — `RegisterPaymentAction` (своя транзакция,
 * идемпотентно по ID попытки и по ID платежа ЮKassa); (2) отметка попытки `succeeded` — `flush`.
 * Падение между фазами оставляет попытку `pending`: повтор (уведомление ЮKassa, возврат клиента,
 * cron `app:billing:yookassa-sync`) получит ту же оплату и закончит фазу 2.
 *
 * Деньги списаны, а зачислить нельзя (сумма не совпала, правила тарифа изменились, пока клиент
 * платил) — попытка `failed_to_apply` и `error`: разбирает администратор. Статус пишется DBAL
 * после отката зачисления, EntityManager к тому моменту может быть закрыт.
 */
final class ApplyYooKassaPaymentAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly YooKassaConfig $config,
        private readonly YooKassaClient $client,
        private readonly BillingCheckoutRepository $checkouts,
        private readonly RegisterPaymentAction $registerPayment,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return CheckoutStatus|null статус попытки после проверки; null — попытки с таким платежом нет
     *
     * @throws YooKassaUnavailableException повторить позже
     * @throws YooKassaRequestFailedException ЮKassa отказала в чтении платежа
     */
    public function __invoke(string $providerPaymentId): ?CheckoutStatus
    {
        $checkout = $this->checkouts->findOneByProviderPaymentId($providerPaymentId);
        if (null === $checkout) {
            $this->logger->warning('Billing: уведомление ЮKassa о неизвестном платеже — пропущено', ['providerPaymentId' => $providerPaymentId]);

            return null;
        }
        if ($checkout->getStatus()->isFinal()) {
            return $checkout->getStatus();
        }
        if (!$this->config->isEnabled()) {
            $this->logger->warning('Billing: онлайн-оплата выключена — платёж ЮKassa не проверен', $this->context($checkout));

            return $checkout->getStatus();
        }

        $payment = $this->client->getPayment($providerPaymentId);
        if ($payment->id !== $providerPaymentId
            || ($payment->metadata['checkout_id'] ?? null) !== $checkout->getId()
            || ($payment->metadata['company_id'] ?? null) !== $checkout->getCompanyId()) {
            $this->logger->error('Billing: платёж ЮKassa не совпадает с попыткой оплаты — не применён', $this->context($checkout));

            return $checkout->getStatus();
        }

        return match ($payment->status) {
            YooKassaPayment::STATUS_SUCCEEDED => $this->applySucceeded($checkout, $payment),
            YooKassaPayment::STATUS_CANCELED => $this->applyCanceled($checkout),
            default => $checkout->getStatus(),
        };
    }

    private function applySucceeded(BillingCheckout $checkout, YooKassaPayment $payment): CheckoutStatus
    {
        if ($payment->amountKopecks !== $checkout->getAmountKopecks() || 'RUB' !== $payment->currency) {
            return $this->failToApply($checkout, 'amount_mismatch', sprintf('ЮKassa: %d %s, ожидалось %d RUB', $payment->amountKopecks, $payment->currency, $checkout->getAmountKopecks()));
        }

        try {
            ($this->registerPayment)(new RegisterPaymentCommand(
                paymentId: $checkout->getId(),
                companyId: $checkout->getCompanyId(),
                tariffCode: $checkout->getTariff()->getCode(),
                months: $checkout->getMonths(),
                amountKopecks: $checkout->getAmountKopecks(),
                // В БД время без зоны, по Москве; ЮKassa отдаёт UTC.
                receivedAt: ($payment->capturedAt ?? $this->clock->now())->setTimezone(new \DateTimeZone('Europe/Moscow')),
                source: PaymentSource::YOOKASSA,
                externalId: $payment->id,
            ));
        } catch (TariffNotPayableException|TariffChangeNotSupportedException|PerpetualTariffChangeNotConfirmedException|InvalidBillingInputException $exception) {
            return $this->failToApply($checkout, 'rejected', $exception->getMessage());
        }

        $checkout->markSucceeded($this->clock->now());
        $this->entityManager->flush();

        return CheckoutStatus::SUCCEEDED;
    }

    private function applyCanceled(BillingCheckout $checkout): CheckoutStatus
    {
        $checkout->markCanceled($this->clock->now());
        $this->entityManager->flush();
        $this->logger->info('Billing: онлайн-оплата отменена', $this->context($checkout));

        return CheckoutStatus::CANCELED;
    }

    private function failToApply(BillingCheckout $checkout, string $code, string $reason): CheckoutStatus
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE billing_checkouts SET status = :status, failure_reason = :reason, updated_at = :now WHERE id = :id AND status = :pending',
            [
                'status' => CheckoutStatus::FAILED_TO_APPLY->value,
                'reason' => mb_substr($reason, 0, 255),
                'now' => $this->clock->now(),
                'id' => $checkout->getId(),
                'pending' => CheckoutStatus::PENDING->value,
            ],
            ['now' => Types::DATETIME_IMMUTABLE],
        );

        $this->logger->error('Billing: оплата ЮKassa получена, но не зачислена — нужен разбор администратора', $this->context($checkout) + [
            'reason' => $code,
            'amountKopecks' => $checkout->getAmountKopecks(),
        ]);

        return CheckoutStatus::FAILED_TO_APPLY;
    }

    /**
     * @return array<string, string>
     */
    private function context(BillingCheckout $checkout): array
    {
        return [
            'companyId' => $checkout->getCompanyId(),
            'checkoutId' => $checkout->getId(),
            'providerPaymentId' => (string) $checkout->getProviderPaymentId(),
        ];
    }
}
