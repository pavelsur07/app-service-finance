<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company\Command;

use App\Company\Command\ResetUserPasswordCommand;
use App\Company\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ResetUserPasswordCommandTest extends TestCase
{
    public function testExecuteReturnsFailureWhenEmailFormatIsInvalid(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $repository->expects(self::never())->method('findOneBy');
        $hasher->expects(self::never())->method('hashPassword');
        $entityManager->expects(self::never())->method('flush');

        $tester = new CommandTester(new ResetUserPasswordCommand($repository, $hasher, $entityManager));
        $exitCode = $tester->execute(['email' => 'not-an-email']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Invalid email format', $tester->getDisplay());
    }

    public function testExecuteReturnsFailureWhenUserIsNotFound(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $repository
            ->expects(self::once())
            ->method('findOneBy')
            ->with(['email' => 'missing@example.com'])
            ->willReturn(null)
        ;

        $hasher->expects(self::never())->method('hashPassword');
        $entityManager->expects(self::never())->method('flush');

        $tester = new CommandTester(new ResetUserPasswordCommand($repository, $hasher, $entityManager));
        $exitCode = $tester->execute(['email' => 'missing@example.com']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('was not found', $tester->getDisplay());
    }

    public function testExecuteResetsPasswordAndFlushesEntityManager(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $user = (new User('11111111-1111-1111-1111-111111111111'))
            ->setEmail('test@example.com')
            ->setPassword('old-hash');

        $repository
            ->expects(self::once())
            ->method('findOneBy')
            ->with(['email' => 'test@example.com'])
            ->willReturn($user)
        ;

        $hasher
            ->expects(self::once())
            ->method('hashPassword')
            ->with(self::identicalTo($user), self::isType('string'))
            ->willReturn('new-hash')
        ;

        $entityManager->expects(self::once())->method('flush');

        $tester = new CommandTester(new ResetUserPasswordCommand($repository, $hasher, $entityManager));
        $exitCode = $tester->execute(['email' => 'TEST@example.com']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('new-hash', $user->getPassword());
        self::assertStringContainsString('has been updated', $tester->getDisplay());
        self::assertMatchesRegularExpression('/New temporary password:\s*\S+/', $tester->getDisplay());
    }
}
