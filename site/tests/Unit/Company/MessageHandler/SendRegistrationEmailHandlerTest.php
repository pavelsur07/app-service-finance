<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company\MessageHandler;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Company\Message\SendRegistrationEmailMessage;
use App\Company\MessageHandler\SendRegistrationEmailHandler;
use App\Company\Repository\UserRepository;
use App\Notification\Contract\NotificationSenderInterface;
use App\Notification\DTO\EmailMessage;
use App\Notification\DTO\NotificationContext;
use App\Notification\Service\NotificationRouter;
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

        $sender = $this->createMock(NotificationSenderInterface::class);
        $sender->method('supports')->willReturn('email');
        $sender->expects($this->once())
            ->method('send')
            ->with($this->isInstanceOf(EmailMessage::class), $this->isInstanceOf(NotificationContext::class))
            ->willReturn(true);
        $notifier = new NotificationRouter([$sender]);

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
