<?php

declare(strict_types=1);

namespace App\Billing\MessageHandler;

use App\Billing\Message\SendBillingExpiryReminderMessage;
use App\Billing\Repository\BillingExpiryReminderRepository;
use App\Billing\Repository\CompanySubscriptionRepository;
use App\Company\Facade\CompanyFacade;
use App\Notification\DTO\EmailMessage;
use App\Notification\Service\NotificationRouter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Отправляет напоминание об окончании оплаты владельцу кабинета. Перед отправкой
 * перепроверяет подписку: продлили или сменили период — письмо не нужно.
 *
 * Не больше одного письма: перед SMTP застолбляется строка `billing_expiry_reminders`
 * (unique). Сбой SMTP снимает отметку и пробрасывается — Messenger повторит. Если SMTP принял
 * письмо, а воркер упал до подтверждения, повтор найдёт отметку и не отправит дубль.
 */
#[AsMessageHandler]
final class SendBillingExpiryReminderHandler
{
    public function __construct(
        private readonly CompanySubscriptionRepository $subscriptions,
        private readonly BillingExpiryReminderRepository $reminders,
        private readonly CompanyFacade $companies,
        private readonly NotificationRouter $notifier,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
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

        $company = $this->companies->findById($message->companyId);
        // Сообщение могло ждать в очереди, пока владелец запросил удаление компании.
        if (null !== $company && $company->isDeletionRequested()) {
            $this->logger->info('Billing: напоминание не нужно — компания помечена к удалению', $context);

            return;
        }

        $email = $company?->getUser()?->getEmail();
        if (null === $email || '' === $email) {
            $this->logger->warning('Billing: напоминание не отправлено — нет адреса владельца', $context);

            return;
        }

        $paidUntil = new \DateTimeImmutable($message->paidUntil);
        if (!$this->reminders->claim($message->companyId, $paidUntil, $message->daysBefore, $this->clock->now())) {
            $this->logger->info('Billing: напоминание уже отправлено — повтор пропущен', $context);

            return;
        }

        try {
            $this->notifier->send('email', new EmailMessage(
                to: $email,
                subject: $message->trial ? 'Пробный период заканчивается' : 'Оплаченный период заканчивается',
                htmlTemplate: 'notifications/email/billing_expiry_reminder.html.twig',
                textTemplate: 'notifications/email/billing_expiry_reminder.txt.twig',
                vars: [
                    'paidUntil' => $paidUntil,
                    'daysBefore' => $message->daysBefore,
                    'trial' => $message->trial,
                    'tariffName' => $subscription->tariffOn($paidUntil)->getName(),
                ],
            ));
        } catch (\Throwable $e) {
            $this->reminders->release($message->companyId, $paidUntil, $message->daysBefore);
            $this->logger->warning('Billing: напоминание не отправлено — повтор через retry', $context + ['exception' => $e]);

            throw $e;
        }

        $this->logger->info('Billing: напоминание об окончании оплаты — отправлено', $context);
    }
}
