<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Company\Service\CompanyOwnerAccountCreator;
use App\Message\SendRegistrationEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CompanyOwnerAccountCreatorTest extends TestCase
{
    public function testCreateBuildsOwnerCompanyAndDispatchesRegistrationEmail(): void
    {
        $user = new User('11111111-1111-1111-1111-111111111111');

        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $passwordHasher
            ->expects(self::once())
            ->method('hashPassword')
            ->with($user, 'plain-password')
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
            ->with(self::callback(static function (object $message) use ($user): bool {
                return $message instanceof SendRegistrationEmailMessage
                    && $message->userId === $user->getId()
                    && '' !== $message->companyId;
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $creator = new CompanyOwnerAccountCreator($passwordHasher, $entityManager, $bus);

        $company = $creator->create($user, 'plain-password', '  Acme LLC  ', true);

        self::assertSame('hashed-password', $user->getPassword());
        self::assertContains('ROLE_COMPANY_OWNER', $user->getRoles());
        self::assertNotContains('ROLE_ADMIN', $user->getRoles());
        self::assertSame('Acme LLC', $company->getName());
        self::assertSame($user, $company->getUser());
        self::assertTrue($user->getCompanies()->contains($company));
        self::assertSame([$user, $company], $persisted);
    }

    public function testCreateCanSkipRegistrationEmailDispatch(): void
    {
        $user = new User('22222222-2222-2222-2222-222222222222');

        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $passwordHasher
            ->expects(self::once())
            ->method('hashPassword')
            ->with($user, 'plain-password')
            ->willReturn('hashed-password');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::exactly(2))
            ->method('persist');
        $entityManager
            ->expects(self::once())
            ->method('flush');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus
            ->expects(self::never())
            ->method('dispatch');

        $creator = new CompanyOwnerAccountCreator($passwordHasher, $entityManager, $bus);

        $company = $creator->create($user, 'plain-password', 'Acme LLC', false);

        self::assertInstanceOf(Company::class, $company);
    }
}
