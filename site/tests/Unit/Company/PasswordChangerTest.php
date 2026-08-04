<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company;

use App\Company\Entity\User;
use App\Company\Exception\InvalidCurrentPasswordException;
use App\Company\Exception\SamePasswordException;
use App\Company\Message\SendPasswordChangedEmailMessage;
use App\Company\Service\PasswordChanger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PasswordChangerTest extends TestCase
{
    public function testChangeHashesPasswordFlushesAndDispatchesEmail(): void
    {
        $user = new User('11111111-1111-1111-1111-111111111111');
        $user->setEmail('user@example.com');
        $user->setPassword('old-hash');

        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $passwordHasher
            ->expects(self::once())
            ->method('isPasswordValid')
            ->with($user, 'current-plain')
            ->willReturn(true);
        $passwordHasher
            ->expects(self::once())
            ->method('hashPassword')
            ->with($user, 'new-plain')
            ->willReturn('new-hash');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('flush');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (object $message) use ($user): bool {
                return $message instanceof SendPasswordChangedEmailMessage
                    && $message->userId === $user->getId();
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $changer = new PasswordChanger($passwordHasher, $entityManager, $bus, new NullLogger());
        $changer->change($user, 'current-plain', 'new-plain');

        self::assertSame('new-hash', $user->getPassword());
    }

    public function testChangeThrowsWhenCurrentPasswordIsInvalid(): void
    {
        $user = new User('22222222-2222-2222-2222-222222222222');
        $user->setPassword('old-hash');

        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $passwordHasher
            ->expects(self::once())
            ->method('isPasswordValid')
            ->with($user, 'wrong-plain')
            ->willReturn(false);
        $passwordHasher
            ->expects(self::never())
            ->method('hashPassword');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::never())
            ->method('flush');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus
            ->expects(self::never())
            ->method('dispatch');

        $changer = new PasswordChanger($passwordHasher, $entityManager, $bus, new NullLogger());

        $this->expectException(InvalidCurrentPasswordException::class);
        $changer->change($user, 'wrong-plain', 'new-plain');
    }

    public function testChangeThrowsWhenNewPasswordEqualsCurrent(): void
    {
        $user = new User('33333333-3333-3333-3333-333333333333');
        $user->setPassword('old-hash');

        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $passwordHasher
            ->expects(self::once())
            ->method('isPasswordValid')
            ->with($user, 'same-plain')
            ->willReturn(true);
        $passwordHasher
            ->expects(self::never())
            ->method('hashPassword');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::never())
            ->method('flush');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus
            ->expects(self::never())
            ->method('dispatch');

        $changer = new PasswordChanger($passwordHasher, $entityManager, $bus, new NullLogger());

        $this->expectException(SamePasswordException::class);
        $changer->change($user, 'same-plain', 'same-plain');
    }
}
