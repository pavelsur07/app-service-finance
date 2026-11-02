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
use App\Billing\Infrastructure\YooKassa\YooKassaAmount;
use App\Billing\Infrastructure\YooKassa\YooKassaClient;
use App\Billing\Infrastructure\YooKassa\YooKassaConfig;
use App\Billing\Infrastructure\YooKassa\YooKassaPayment;
use App\Billing\Repository\BillingCheckoutRepository;
use App\Billing\Repository\BillingPaymentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Применяет состояние платежа ЮKassa к попытке оплаты. Истина — `GET /payments/{id}` из ЮKassa;
 * уведомлению и параметрам возврата не доверяем, они только повод проверить.
 *
 * Точка входа, сама коммитит. Фазы: (1) зачисление — `RegisterPaymentAction` (своя транзакция,
 * идемпотентно по ID попытки и по ID платежа ЮKassa); (2) отметка попытки `succeeded` — DBAL
 * `UPDATE … WHERE status = 'pending'` (автокоммит; EntityManager после отката фазы 1 может быть закрыт).
 * Падение между фазами оставляет попытку `pending`: повтор (уведомление ЮKassa, возврат клиента,
 * cron `app:billing:yookassa-sync`) получит ту же оплату и закончит фазу 2.
 *
 * Деньги списаны, а зачислить нельзя (сумма не совпала, правила тарифа изменились, пока клиент
 * платил), платежа нет в магазине (404) или metadata не совпала — попытка `failed_to_apply` и
 * `error`: разбирает администратор.
 */
final class ApplyYooKassaPaymentAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly YooKassaConfig $config,
        private readonly YooKassaClient $client,
        private readonly BillingCheckoutRepository $checkouts,
        private readonly BillingPaymentRepository $payments,
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
        // Статус — из БД: переходы пишутся DBAL, объект в identity map их не видит.
        $status = CheckoutStatus::from((string) $this->entityManager->getConnection()->fetchOne('SELECT status FROM billing_checkouts WHERE id = ?', [$checkout->getId()]));
        if ($status->isFinal()) {
            return $status;
        }
        if (!$this->config->isEnabled()) {
            $this->logger->warning('Billing: онлайн-оплата выключена — платёж ЮKassa не проверен', $this->context($checkout));

            return $status;
        }

        try {
            $payment = $this->client->getPayment($providerPaymentId);
        } catch (YooKassaRequestFailedException $exception) {
            if (404 === $exception->getStatusCode()) {
                // Платежа нет в этом магазине (например, создан тестовым ключом до перехода на боевой).
                return $this->failToApply($checkout, 'not_found', 'Платёж не найден в ЮKassa текущего магазина');
            }
            $this->touch($checkout);

            throw $exception;
        }
        if ($payment->id !== $providerPaymentId
            || ($payment->metadata['checkout_id'] ?? null) !== $checkout->getId()
            || ($payment->metadata['company_id'] ?? null) !== $checkout->getCompanyId()) {
            return $this->failToApply($checkout, 'metadata_mismatch', 'Платёж ЮKassa не совпадает с попыткой оплаты (metadata)');
        }

        return match ($payment->status) {
            YooKassaPayment::STATUS_SUCCEEDED => $this->applySucceeded($checkout, $payment),
            YooKassaPayment::STATUS_CANCELED => $this->applyCanceled($checkout),
            default => $this->touch($checkout),
        };
    }

    private function applySucceeded(BillingCheckout $checkout, YooKassaPayment $payment): CheckoutStatus
    {
        if ($payment->amountKopecks !== $checkout->getAmountKopecks() || 'RUB' !== $payment->currency) {
            return $this->failToApply($checkout, 'amount_mismatch', sprintf('ЮKassa: %s %s, ожидалось %s RUB', YooKassaAmount::format($payment->amountKopecks), $payment->currency, YooKassaAmount::format($checkout->getAmountKopecks())));
        }

        // Добор после сбоя между фазами: оплата уже зачислена — не прогонять правила тарифа заново
        // (тариф могли отключить или назначить «Друга» после зачисления).
        // То же, если администратор уже отметил этот платёж вручную (ID платежа ЮKassa в номере).
        if (null !== $this->payments->findOneByIdAndCompanyId($checkout->getId(), $checkout->getCompanyId())
            || null !== $this->payments->findOneByCompanyAndExternalIdAnySource($checkout->getCompanyId(), $payment->id)) {
            return $this->transition($checkout, CheckoutStatus::SUCCEEDED, null);
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

        $this->logger->info('Billing: онлайн-оплата зачислена', $this->context($checkout));

        return $this->transition($checkout, CheckoutStatus::SUCCEEDED, null);
    }

    private function applyCanceled(BillingCheckout $checkout): CheckoutStatus
    {
        $this->logger->info('Billing: онлайн-оплата отменена', $this->context($checkout));

        return $this->transition($checkout, CheckoutStatus::CANCELED, null);
    }

    private function failToApply(BillingCheckout $checkout, string $code, string $reason): CheckoutStatus
    {
        $this->logger->error('Billing: оплата ЮKassa не зачислена автоматически — нужен разбор администратора', $this->context($checkout) + [
            'reason' => $code,
            'amountKopecks' => $checkout->getAmountKopecks(),
        ]);

        return $this->transition($checkout, CheckoutStatus::FAILED_TO_APPLY, $reason);
    }

    /**
     * Смена статуса — DBAL, только из `pending`: после отката зачисления EntityManager может
     * быть закрыт, а параллельная проверка могла уже завершить попытку (тогда возвращается её статус).
     */
    private function transition(BillingCheckout $checkout, CheckoutStatus $status, ?string $reason): CheckoutStatus
    {
        $connection = $this->entityManager->getConnection();
        $changed = $connection->executeStatement(
            'UPDATE billing_checkouts SET status = :status, failure_reason = :reason, updated_at = :now WHERE id = :id AND status = :pending',
            [
                'status' => $status->value,
                'reason' => null === $reason ? null : mb_substr($reason, 0, 255),
                'now' => $this->clock->now(),
                'id' => $checkout->getId(),
                'pending' => CheckoutStatus::PENDING->value,
            ],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
        if (0 === $changed) {
            $current = $connection->fetchOne('SELECT status FROM billing_checkouts WHERE id = ?', [$checkout->getId()]);

            return CheckoutStatus::tryFrom((string) $current) ?? $status;
        }

        return $status;
    }

    /**
     * Попытка ещё не завершена — отметить время проверки: cron-сверка берёт давно не проверенные первыми.
     */
    private function touch(BillingCheckout $checkout): CheckoutStatus
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE billing_checkouts SET updated_at = :now WHERE id = :id AND status = :pending',
            ['now' => $this->clock->now(), 'id' => $checkout->getId(), 'pending' => CheckoutStatus::PENDING->value],
            ['now' => Types::DATETIME_IMMUTABLE],
        );

        return CheckoutStatus::PENDING;
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
