<?php

declare(strict_types=1);

namespace App\Billing\MessageHandler;

use App\Billing\Application\ApplyYooKassaPaymentAction;
use App\Billing\Exception\YooKassaRequestFailedException;
use App\Billing\Exception\YooKassaUnavailableException;
use App\Billing\Message\ApplyYooKassaPaymentMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final class ApplyYooKassaPaymentHandler
{
    public function __construct(
        private readonly ApplyYooKassaPaymentAction $apply,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ApplyYooKassaPaymentMessage $message): void
    {
        $context = ['providerPaymentId' => $message->providerPaymentId];
        $this->logger->info('Billing: проверка платежа ЮKassa — старт', $context);

        try {
            $status = ($this->apply)($message->providerPaymentId);
        } catch (YooKassaUnavailableException $exception) {
            $this->logger->warning('Billing: ЮKassa недоступна — проверка платежа повторится', $context);

            throw new RecoverableMessageHandlingException($exception->getMessage(), 0, $exception);
        } catch (YooKassaRequestFailedException $exception) {
            // Сам клиент уже записал error с кодом ответа; повтор не поможет, добор — cron-сверкой.
            throw new UnrecoverableMessageHandlingException($exception->getMessage(), 0, $exception);
        }

        $this->logger->info('Billing: проверка платежа ЮKassa — финиш', $context + ['status' => null === $status ? 'unknown' : $status->value]);
    }
}
