<?php

declare(strict_types=1);

namespace App\Company\MessageHandler;

use App\Company\Entity\User;
use App\Company\Message\SendPasswordChangedEmailMessage;
use App\Company\Repository\UserRepository;
use App\Notification\DTO\EmailMessage;
use App\Notification\Service\NotificationRouter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SendPasswordChangedEmailHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly NotificationRouter $notifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendPasswordChangedEmailMessage $message): void
    {
        $user = $this->users->find($message->userId);

        if (!$user instanceof User) {
            $this->logger->warning('Password changed email: user not found', [
                'userId' => $message->userId,
                'changedAt' => $message->changedAt->format(\DATE_ATOM),
            ]);

            return;
        }

        $email = $user->getEmail();
        if (empty($email)) {
            $this->logger->warning('Password changed email: user email is empty', [
                'userId' => $message->userId,
                'changedAt' => $message->changedAt->format(\DATE_ATOM),
            ]);

            return;
        }

        $emailMessage = new EmailMessage(
            to: $email,
            subject: 'Пароль изменён',
            htmlTemplate: 'notifications/email/password_changed.html.twig',
            textTemplate: 'notifications/email/password_changed.txt.twig',
            vars: [
                'user' => $user,
                'changedAt' => $message->changedAt,
            ],
        );

        $sent = $this->notifier->send('email', $emailMessage);
        if (!$sent) {
            // Уведомление о смене пароля security-критично: тихий дроп недопустим
            $this->logger->warning('Password changed email was not sent', [
                'userId' => $message->userId,
                'changedAt' => $message->changedAt->format(\DATE_ATOM),
            ]);
        }
    }
}
