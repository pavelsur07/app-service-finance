<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\User;
use App\Company\Repository\CompanyMemberRepository;
use App\Company\Service\CompanyOwnerAccountCreator;
use App\Company\Message\SendRegistrationEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CompanyOwnerAccountCreatorTest extends TestCase
{
    public function testCreateBuildsOwnerCompanyMemberAndDispatchesRegistrationEmail(): void
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
            ->expects(self::exactly(3))
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });
        $entityManager
            ->expects(self::once())
            ->method('flush');

        $companyMemberRepository = $this->createMock(CompanyMemberRepository::class);
        $companyMemberRepository
            ->expects(self::once())
            ->method('findOneByCompanyAndUser')
            ->with(self::isInstanceOf(Company::class), $user)
            ->willReturn(null);

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

        $creator = new CompanyOwnerAccountCreator($passwordHasher, $entityManager, $bus, $companyMemberRepository);

        $company = $creator->create($user, 'plain-password', '  Acme LLC  ', true);

        self::assertSame('hashed-password', $user->getPassword());
        self::assertContains('ROLE_COMPANY_OWNER', $user->getRoles());
        self::assertNotContains('ROLE_ADMIN', $user->getRoles());
        self::assertSame('Acme LLC', $company->getName());
        self::assertSame($user, $company->getUser());
        self::assertTrue($user->getCompanies()->contains($company));
        self::assertCount(3, $persisted);
        self::assertSame($user, $persisted[0]);
        self::assertSame($company, $persisted[1]);
        self::assertInstanceOf(CompanyMember::class, $persisted[2]);
        self::assertNotNull($persisted[2]->getId());
        self::assertTrue(Uuid::isValid($persisted[2]->getId()));
        self::assertSame($company, $persisted[2]->getCompany());
        self::assertSame($user, $persisted[2]->getUser());
        self::assertSame(CompanyMember::ROLE_OWNER, $persisted[2]->getRole());
    }

    public function testCreateDoesNotPersistDuplicateCompanyMemberWhenRelationAlreadyExists(): void
    {
        $user = new User('22222222-2222-2222-2222-222222222222');

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

        $companyMemberRepository = $this->createMock(CompanyMemberRepository::class);
        $companyMemberRepository
            ->expects(self::once())
            ->method('findOneByCompanyAndUser')
            ->with(self::isInstanceOf(Company::class), $user)
            ->willReturnCallback(static function (Company $company, User $user): CompanyMember {
                return new CompanyMember(
                    '33333333-3333-3333-3333-333333333333',
                    $company,
                    $user,
                    CompanyMember::ROLE_OWNER,
                );
            });

        $bus = $this->createMock(MessageBusInterface::class);
        $bus
            ->expects(self::never())
            ->method('dispatch');

        $creator = new CompanyOwnerAccountCreator($passwordHasher, $entityManager, $bus, $companyMemberRepository);

        $company = $creator->create($user, 'plain-password', 'Acme LLC', false);

        self::assertInstanceOf(Company::class, $company);
        self::assertSame([$user, $company], $persisted);
    }

    public function testCreateCanSkipRegistrationEmailDispatch(): void
    {
        $user = new User('44444444-4444-4444-4444-444444444444');

        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $passwordHasher
            ->expects(self::once())
            ->method('hashPassword')
            ->with($user, 'plain-password')
            ->willReturn('hashed-password');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::exactly(3))
            ->method('persist');
        $entityManager
            ->expects(self::once())
            ->method('flush');

        $companyMemberRepository = $this->createMock(CompanyMemberRepository::class);
        $companyMemberRepository
            ->expects(self::once())
            ->method('findOneByCompanyAndUser')
            ->with(self::isInstanceOf(Company::class), $user)
            ->willReturn(null);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus
            ->expects(self::never())
            ->method('dispatch');

        $creator = new CompanyOwnerAccountCreator($passwordHasher, $entityManager, $bus, $companyMemberRepository);

        $company = $creator->create($user, 'plain-password', 'Acme LLC', false);

        self::assertInstanceOf(Company::class, $company);
    }
}
