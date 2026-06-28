<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin\Application;

use App\Admin\Application\CreateAccountAction;
use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\User;
use App\Company\Application\Service\CompanyOwnerMembershipCreator;
use App\Company\Facade\CompanyFacade;
use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Company\Service\CompanyOwnerAccountCreator;
use App\Company\Message\SendRegistrationEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(CreateAccountAction::class)]
final class CreateAccountActionTest extends TestCase
{
    public function testCreatesOwnerAccountWithCompanyMemberViaCompanyFacade(): void
    {
        $account = new User('22222222-2222-4222-8222-222222222222');
        $account->setEmail('new-owner@example.test');

        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $passwordHasher
            ->expects(self::once())
            ->method('hashPassword')
            ->with(
                self::callback(static fn (User $user): bool => 'new-owner@example.test' === $user->getEmail()),
                'plain-password',
            )
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

        $bus = $this->createMock(MessageBusInterface::class);
        $bus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(SendRegistrationEmailMessage::class))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $accountCreator = new CompanyOwnerAccountCreator(
            $passwordHasher,
            $entityManager,
            $bus,
            new CompanyOwnerMembershipCreator($entityManager),
        );
        $companyFacade = new CompanyFacade($this->createMock(CompanyRepository::class), $accountCreator);
        $action = new CreateAccountAction($companyFacade);

        $company = $action($account, 'plain-password', '  ООО Ромашка  ');

        self::assertInstanceOf(User::class, $persisted[0]);
        self::assertNotSame($account, $persisted[0]);
        self::assertSame('new-owner@example.test', $persisted[0]->getEmail());
        self::assertSame('hashed-password', $persisted[0]->getPassword());
        self::assertContains('ROLE_COMPANY_OWNER', $persisted[0]->getRoles());
        self::assertNotContains('ROLE_ADMIN', $persisted[0]->getRoles());
        self::assertSame('ООО Ромашка', $company->getName());
        self::assertSame($persisted[0], $company->getUser());
        self::assertContains($company, $persisted);
        self::assertInstanceOf(Company::class, $company);
        self::assertInstanceOf(CompanyMember::class, $persisted[2]);
        self::assertSame($persisted[0], $persisted[2]->getUser());
        self::assertSame(CompanyMember::ROLE_OWNER, $persisted[2]->getRole());
    }
}
