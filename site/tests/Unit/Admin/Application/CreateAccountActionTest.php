<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin\Application;

use App\Admin\Application\CreateAccountAction;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Message\SendRegistrationEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(CreateAccountAction::class)]
final class CreateAccountActionTest extends TestCase
{
    public function testCreatesOwnerAccountWithCompanyAndDispatchesRegistrationEmail(): void
    {
        $account = new User('22222222-2222-4222-8222-222222222222');
        $account->setEmail('new-owner@example.test');

        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $passwordHasher
            ->expects(self::once())
            ->method('hashPassword')
            ->with($account, 'plain-password')
            ->willReturn('hashed-password');

        $persisted = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::exactly(2))
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });
        $entityManager
            ->expects(self::once())
            ->method('flush');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(SendRegistrationEmailMessage::class))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $action = new CreateAccountAction($passwordHasher, $entityManager, $bus);
        $company = $action($account, 'plain-password', '  ООО Ромашка  ');

        self::assertSame('hashed-password', $account->getPassword());
        self::assertContains('ROLE_COMPANY_OWNER', $account->getRoles());
        self::assertSame('ООО Ромашка', $company->getName());
        self::assertSame($account, $company->getUser());
        self::assertContains($account, $persisted);
        self::assertContains($company, $persisted);
        self::assertInstanceOf(Company::class, $company);
    }
}
