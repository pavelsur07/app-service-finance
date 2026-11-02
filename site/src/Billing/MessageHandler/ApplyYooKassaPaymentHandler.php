<?php

declare(strict_types=1);

namespace App\Billing\MessageHandler;

use App\Billing\Application\ApplyYooKassaPaymentAction;
use App\Billing\Exception\YooKassaRequestFailedException;
use App\Billing\Exception\YooKassaUnavailableException;
use App\Billing\Message\ApplyYooKassaPaymentMessage;
use App\Billing\Repository\BillingCheckoutRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final class ApplyYooKassaPaymentHandler
{
    public function __construct(
        private readonly ApplyYooKassaPaymentAction $apply,
        private readonly BillingCheckoutRepository $checkouts,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ApplyYooKassaPaymentMessage $message): void
    {
        $context = [
            'providerPaymentId' => $message->providerPaymentId,
            'companyId' => $this->checkouts->findOneByProviderPaymentId($message->providerPaymentId)?->getCompanyId() ?? 'unknown',
        ];
        $this->logger->info('Billing: проверка платежа ЮKassa — старт', $context);

        try {
            $status = ($this->apply)($message->providerPaymentId);
        } catch (YooKassaUnavailableException $exception) {
            // Обычное исключение, не Recoverable: повторы ограничены retry_strategy транспорта
            // (Recoverable повторялся бы бесконечно), дальше добирает cron-сверка.
            $this->logger->warning('Billing: ЮKassa недоступна — проверка платежа повторится', $context);

            throw $exception;
        } catch (YooKassaRequestFailedException $exception) {
            // Сам клиент уже записал error с кодом ответа; повтор не поможет, добор — cron-сверкой.
            throw new UnrecoverableMessageHandlingException($exception->getMessage(), 0, $exception);
        }

        $this->logger->info('Billing: проверка платежа ЮKassa — финиш', $context + ['status' => null === $status ? 'unknown' : $status->value]);
    }
}
