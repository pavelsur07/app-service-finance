<?php

namespace App\Tests\MessageHandler;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Message\SendRegistrationEmailMessage;
use App\MessageHandler\SendRegistrationEmailHandler;
use App\Notification\DTO\EmailMessage;
use App\Notification\Service\NotificationRouter;
use App\Repository\UserRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

class SendRegistrationEmailHandlerTest extends TestCase
{
    public function testInvokeSendsRegistrationEmail(): void
    {
        $userId = Uuid::uuid4()->toString();
        $companyId = Uuid::uuid4()->toString();

        $user = new User($userId);
        $user->setEmail('user@example.com');
        $user->setPassword('secret');
        $company = new Company($companyId, $user);
        $company->setName('Test Company');

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())
            ->method('find')
            ->with($userId)
            ->willReturn($user);

        $companies = $this->createMock(CompanyRepository::class);
        $companies->expects($this->once())
            ->method('find')
            ->with($companyId)
            ->willReturn($company);

        $notifier = $this->createMock(NotificationRouter::class);
        $notifier->expects($this->once())
            ->method('send')
            ->with('email', $this->isInstanceOf(EmailMessage::class))
            ->willReturn(true);

        $logger = $this->createMock(LoggerInterface::class);

        $handler = new SendRegistrationEmailHandler(
            $users,
            $companies,
            $notifier,
            $logger,
        );

        $message = new SendRegistrationEmailMessage(
            $userId,
            $companyId,
            new \DateTimeImmutable('2024-01-01 10:00:00'),
        );

        $handler->__invoke($message);
    }
}
