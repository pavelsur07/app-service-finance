<?php

namespace App\MessageHandler;

use App\Company\Entity\Company;
use App\Entity\User;
use App\Message\SendRegistrationEmailMessage;
use App\Notification\DTO\EmailMessage;
use App\Notification\Service\NotificationRouter;
use App\Repository\CompanyRepository;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SendRegistrationEmailHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly CompanyRepository $companies,
        private readonly NotificationRouter $notifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendRegistrationEmailMessage $message): void
    {
        $user = $this->users->find($message->userId);
        $company = $this->companies->find($message->companyId);

        if (!$user instanceof User || !$company instanceof Company) {
            $this->logger->warning('Registration email: user or company not found', [
                'userId' => $message->userId,
                'companyId' => $message->companyId,
                'createdAt' => $message->createdAt->format(\DATE_ATOM),
            ]);

            return;
        }

        $email = $user->getEmail();
        if (empty($email)) {
            $this->logger->warning('Registration email: user email is empty', [
                'userId' => $message->userId,
                'companyId' => $message->companyId,
                'createdAt' => $message->createdAt->format(\DATE_ATOM),
            ]);

            return;
        }

        $emailMessage = new EmailMessage(
            to: $email,
            subject: 'Регистрация завершена',
            htmlTemplate: 'notifications/email/registration_success.html.twig',
            textTemplate: 'notifications/email/registration_success.txt.twig',
            vars: [
                'user' => $user,
                'company' => $company,
            ],
        );

        $this->notifier->send('email', $emailMessage);
    }
}
