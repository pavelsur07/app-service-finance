<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company\MessageHandler;

use App\Company\Entity\User;
use App\Company\Message\SendPasswordChangedEmailMessage;
use App\Company\MessageHandler\SendPasswordChangedEmailHandler;
use App\Company\Repository\UserRepository;
use App\Notification\Contract\NotificationSenderInterface;
use App\Notification\DTO\EmailMessage;
use App\Notification\Service\NotificationRouter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

final class SendPasswordChangedEmailHandlerTest extends TestCase
{
    public function testInvokeSendsPasswordChangedEmail(): void
    {
        $userId = Uuid::uuid4()->toString();

        $user = new User($userId);
        $user->setEmail('user@example.com');
        $user->setPassword('secret');

        $users = $this->createMock(UserRepository::class);
        $users->expects(self::once())
            ->method('find')
            ->with($userId)
            ->willReturn($user);

        $sender = $this->createMock(NotificationSenderInterface::class);
        $sender->method('supports')->willReturn('email');
        $sender->expects(self::once())
            ->method('send')
            ->with(self::callback(static function (object $message): bool {
                return $message instanceof EmailMessage
                    && 'user@example.com' === $message->to
                    && 'Пароль изменён' === $message->subject
                    && 'notifications/email/password_changed.html.twig' === $message->htmlTemplate
                    && 'notifications/email/password_changed.txt.twig' === $message->textTemplate;
            }), self::anything())
            ->willReturn(true);
        $notifier = new NotificationRouter([$sender]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $handler = new SendPasswordChangedEmailHandler($users, $notifier, $logger);
        $handler->__invoke(new SendPasswordChangedEmailMessage($userId, new \DateTimeImmutable()));
    }

    public function testInvokeLogsWarningWhenUserNotFound(): void
    {
        $userId = Uuid::uuid4()->toString();

        $users = $this->createMock(UserRepository::class);
        $users->expects(self::once())
            ->method('find')
            ->with($userId)
            ->willReturn(null);

        $sender = $this->createMock(NotificationSenderInterface::class);
        $sender->method('supports')->willReturn('email');
        $sender->expects(self::never())->method('send');
        $notifier = new NotificationRouter([$sender]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $handler = new SendPasswordChangedEmailHandler($users, $notifier, $logger);
        $handler->__invoke(new SendPasswordChangedEmailMessage($userId, new \DateTimeImmutable()));
    }

    public function testInvokeLogsWarningWhenSendFails(): void
    {
        $userId = Uuid::uuid4()->toString();

        $user = new User($userId);
        $user->setEmail('user@example.com');
        $user->setPassword('secret');

        $users = $this->createMock(UserRepository::class);
        $users->expects(self::once())
            ->method('find')
            ->with($userId)
            ->willReturn($user);

        // Без зарегистрированного sender'а NotificationRouter::send() вернёт false
        $notifier = new NotificationRouter([]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $handler = new SendPasswordChangedEmailHandler($users, $notifier, $logger);
        $handler->__invoke(new SendPasswordChangedEmailMessage($userId, new \DateTimeImmutable()));
    }
}
