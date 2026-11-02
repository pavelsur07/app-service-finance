<?php

declare(strict_types=1);

namespace App\Billing\MessageHandler;

use App\Billing\Message\SendBillingExpiryReminderMessage;
use App\Billing\Repository\CompanySubscriptionRepository;
use App\Company\Facade\CompanyFacade;
use App\Notification\DTO\EmailMessage;
use App\Notification\Service\NotificationRouter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Отправляет напоминание об окончании оплаты владельцу кабинета. Перед отправкой
 * перепроверяет подписку: продлили или сменили период — письмо не нужно.
 */
#[AsMessageHandler]
final class SendBillingExpiryReminderHandler
{
    public function __construct(
        private readonly CompanySubscriptionRepository $subscriptions,
        private readonly CompanyFacade $companies,
        private readonly NotificationRouter $notifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendBillingExpiryReminderMessage $message): void
    {
        $context = ['companyId' => $message->companyId, 'paidUntil' => $message->paidUntil, 'daysBefore' => $message->daysBefore];
        $this->logger->info('Billing: напоминание об окончании оплаты — старт', $context);

        $subscription = $this->subscriptions->findOneByCompanyId($message->companyId);
        if ($subscription?->getPaidUntil()?->format('Y-m-d') !== $message->paidUntil) {
            $this->logger->info('Billing: напоминание не нужно — период изменился', $context);

            return;
        }

        $email = $this->companies->findById($message->companyId)?->getUser()?->getEmail();
        if (null === $email || '' === $email) {
            $this->logger->warning('Billing: напоминание не отправлено — нет адреса владельца', $context);

            return;
        }

        $this->notifier->send('email', new EmailMessage(
            to: $email,
            subject: $message->trial ? 'Пробный период заканчивается' : 'Оплаченный период заканчивается',
            htmlTemplate: 'notifications/email/billing_expiry_reminder.html.twig',
            textTemplate: 'notifications/email/billing_expiry_reminder.txt.twig',
            vars: [
                'paidUntil' => new \DateTimeImmutable($message->paidUntil),
                'daysBefore' => $message->daysBefore,
                'trial' => $message->trial,
                'tariffName' => $subscription->getTariff()->getName(),
            ],
        ));

        $this->logger->info('Billing: напоминание об окончании оплаты — отправлено', $context);
    }
}
