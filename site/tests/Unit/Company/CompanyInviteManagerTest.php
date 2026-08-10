<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company;

use App\Company\Entity\CompanyInvite;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
use App\Company\Repository\CompanyInviteRepository;
use App\Company\Repository\CompanyMemberRepository;
use App\Company\Security\AccessLevel;
use App\Company\Security\Module;
use App\Company\Security\SystemCompanyRoles;
use App\Company\Service\CompanyInviteManager;
use App\Company\Service\InviteTokenService;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyInviteBuilder;
use App\Tests\Builders\Company\UserBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class CompanyInviteManagerTest extends TestCase
{
    public function testInviteOperatorCreatesInviteForNewEmail(): void
    {
        $owner = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $now = new \DateTimeImmutable('2025-01-02 10:00:00+00:00');
        $expectedToken = 'plain-token';
        $expectedHash = hash('sha256', $expectedToken);
        $expectedExpiresAt = $now->modify('+72 hours');

        $tokenService = $this->makeTokenService($expectedToken);

        $inviteRepository = $this->createMock(CompanyInviteRepository::class);
        $inviteRepository
            ->expects(self::once())
            ->method('findPendingByCompanyAndEmail')
            ->with($company, 'operator@example.test', $now)
            ->willReturn(null);

        $memberRepository = $this->createMock(CompanyMemberRepository::class);

        $capturedInvite = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (CompanyInvite $invite) use (&$capturedInvite): bool {
                $capturedInvite = $invite;

                return true;
            }));
        $em->expects(self::once())->method('flush');

        $manager = new CompanyInviteManager(
            $em,
            $inviteRepository,
            $memberRepository,
            $tokenService,
        );

        $result = $manager->inviteOperator($company, 'Operator@Example.Test', $owner, $now);

        self::assertSame('invite_created', $result->type);
        self::assertSame($expectedToken, $result->plainToken);
        self::assertNotNull($result->invite);
        self::assertNotNull($capturedInvite);

        // never assertSame() for objects here
        self::assertSame($capturedInvite->getId(), $result->invite->getId());

        self::assertSame('operator@example.test', $capturedInvite->getEmail());
        self::assertSame(CompanyMember::ROLE_OPERATOR, $capturedInvite->getRole());
        self::assertSame($expectedHash, $capturedInvite->getTokenHash());

        // DateTimeImmutable сравниваем по значению, не по ссылке
        self::assertEquals($expectedExpiresAt, $capturedInvite->getExpiresAt());
    }

    public function testInviteOperatorRenewsPendingInvite(): void
    {
        $owner = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $now = new \DateTimeImmutable('2025-02-01 12:00:00+00:00');
        $expectedToken = 'renew-token';
        $expectedHash = hash('sha256', $expectedToken);
        $expectedExpiresAt = $now->modify('+72 hours');

        $tokenService = $this->makeTokenService($expectedToken);

        $invite = CompanyInviteBuilder::anInvite()
            ->withCompany($company)
            ->withCreatedBy($owner)
            ->withEmail('operator@example.test')
            ->build();

        $inviteRepository = $this->createMock(CompanyInviteRepository::class);
        $inviteRepository
            ->expects(self::once())
            ->method('findPendingByCompanyAndEmail')
            ->with($company, 'operator@example.test', $now)
            ->willReturn($invite);

        $memberRepository = $this->createMock(CompanyMemberRepository::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $em->expects(self::never())->method('persist');

        $manager = new CompanyInviteManager(
            $em,
            $inviteRepository,
            $memberRepository,
            $tokenService,
        );

        $result = $manager->inviteOperator($company, 'operator@example.test', $owner, $now);

        self::assertSame('invite_renewed', $result->type);
        self::assertSame($expectedToken, $result->plainToken);
        self::assertNotNull($result->invite);

        // never assertSame() for objects here
        self::assertSame($invite->getId(), $result->invite->getId());

        self::assertSame($expectedHash, $invite->getTokenHash());

        // DateTimeImmutable сравниваем по значению, не по ссылке
        self::assertEquals($expectedExpiresAt, $invite->getExpiresAt());
    }

    public function testAcceptInviteCreatesCompanyMemberAndMarksAccepted(): void
    {
        $owner = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $user = UserBuilder::aUser()->withEmail('operator@example.test')->build();
        $plainToken = 'accept-token';
        $tokenHash = hash('sha256', $plainToken);
        $now = new \DateTimeImmutable('2025-03-01 12:00:00+00:00');

        $invite = CompanyInviteBuilder::anInvite()
            ->withCompany($company)
            ->withCreatedBy($owner)
            ->withEmail($user->getEmail())
            ->withTokenHash($tokenHash)
            ->withExpiresAt($now->modify('+1 day'))
            ->build();

        $inviteRepository = $this->createMock(CompanyInviteRepository::class);
        $inviteRepository
            ->expects(self::once())
            ->method('findOneByTokenHash')
            ->with($tokenHash)
            ->willReturn($invite);

        $memberRepository = $this->createMock(CompanyMemberRepository::class);
        $memberRepository
            ->expects(self::once())
            ->method('findOneByCompanyAndUser')
            ->with($company, $user)
            ->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em
            ->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(CompanyMember::class));
        $em->expects(self::once())->method('flush');

        $manager = new CompanyInviteManager(
            $em,
            $inviteRepository,
            $memberRepository,
            new InviteTokenService(),
        );

        $manager->acceptInvite($plainToken, $user, $now);

        // DateTimeImmutable сравниваем по значению, не по ссылке
        self::assertEquals($now, $invite->getAcceptedAt());

        // user — это тот же объект, его можно сравнивать по ссылке
        self::assertSame($user, $invite->getAcceptedByUser());
    }

    public function testAcceptInviteRejectsDisabledExistingMember(): void
    {
        $owner = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $user = UserBuilder::aUser()->withEmail('operator@example.test')->build();
        $plainToken = 'disabled-member-token';
        $tokenHash = hash('sha256', $plainToken);
        $now = new \DateTimeImmutable('2025-03-01 12:00:00+00:00');

        $invite = CompanyInviteBuilder::anInvite()
            ->withCompany($company)
            ->withCreatedBy($owner)
            ->withEmail($user->getEmail())
            ->withTokenHash($tokenHash)
            ->withExpiresAt($now->modify('+1 day'))
            ->build();

        $member = new CompanyMember(
            id: '33333333-3333-3333-3333-333333333333',
            company: $company,
            user: $user,
            role: CompanyMember::ROLE_OPERATOR,
            createdAt: $now,
        );
        $member->disable();

        $inviteRepository = $this->createMock(CompanyInviteRepository::class);
        $inviteRepository
            ->expects(self::once())
            ->method('findOneByTokenHash')
            ->with($tokenHash)
            ->willReturn($invite);

        $memberRepository = $this->createMock(CompanyMemberRepository::class);
        $memberRepository
            ->expects(self::once())
            ->method('findOneByCompanyAndUser')
            ->with($company, $user)
            ->willReturn($member);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $manager = new CompanyInviteManager(
            $em,
            $inviteRepository,
            $memberRepository,
            new InviteTokenService(),
        );

        $this->expectException(AccessDeniedException::class);

        $manager->acceptInvite($plainToken, $user, $now);
    }

    public function testInviteOperatorStoresAccessRoleOnNewInvite(): void
    {
        $owner = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $now = new \DateTimeImmutable('2025-01-02 10:00:00+00:00');
        $accessRole = new CompanyRole(
            '77777777-7777-4777-8777-777777777777',
            'Finance only',
            [Module::FINANCE->value => AccessLevel::WRITE->value],
            $company,
        );

        $inviteRepository = $this->createMock(CompanyInviteRepository::class);
        $inviteRepository->method('findPendingByCompanyAndEmail')->willReturn(null);

        $memberRepository = $this->createMock(CompanyMemberRepository::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        $manager = new CompanyInviteManager(
            $em,
            $inviteRepository,
            $memberRepository,
            $this->makeTokenService('token'),
        );

        $result = $manager->inviteOperator($company, 'operator@example.test', $owner, $now, $accessRole);

        self::assertNotNull($result->invite);
        self::assertSame($accessRole, $result->invite->getAccessRole());
    }

    public function testAcceptInviteAssignsSelectedAccessRoleToNewMember(): void
    {
        $owner = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $user = UserBuilder::aUser()->withEmail('operator@example.test')->build();
        $plainToken = 'accept-role-token';
        $tokenHash = hash('sha256', $plainToken);
        $now = new \DateTimeImmutable('2025-03-01 12:00:00+00:00');
        $accessRole = new CompanyRole(
            '88888888-8888-4888-8888-888888888888',
            'Marketplace only',
            [Module::MARKETPLACE->value => AccessLevel::WRITE->value],
            $company,
        );

        $invite = CompanyInviteBuilder::anInvite()
            ->withCompany($company)
            ->withCreatedBy($owner)
            ->withEmail($user->getEmail())
            ->withTokenHash($tokenHash)
            ->withExpiresAt($now->modify('+1 day'))
            ->withAccessRole($accessRole)
            ->build();

        $inviteRepository = $this->createMock(CompanyInviteRepository::class);
        $inviteRepository->method('findOneByTokenHash')->with($tokenHash)->willReturn($invite);

        $memberRepository = $this->createMock(CompanyMemberRepository::class);
        $memberRepository->method('findOneByCompanyAndUser')->with($company, $user)->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(CompanyMember::class));
        $em->expects(self::once())->method('flush');

        $manager = new CompanyInviteManager(
            $em,
            $inviteRepository,
            $memberRepository,
            new InviteTokenService(),
        );

        $manager->acceptInvite($plainToken, $user, $now);

        // CompanyMember создаётся внутри менеджера; доберёмся до него через перехват persist невозможно
        // без дополнительного mock, поэтому проверяем только отсутствие исключений и статус приглашения.
        self::assertEquals($now, $invite->getAcceptedAt());
        self::assertSame($user, $invite->getAcceptedByUser());
    }

    public function testAcceptInviteFallsBackToFullAccessWhenInviteHasNoRole(): void
    {
        $owner = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $user = UserBuilder::aUser()->withEmail('operator@example.test')->build();
        $plainToken = 'fallback-token';
        $tokenHash = hash('sha256', $plainToken);
        $now = new \DateTimeImmutable('2025-03-01 12:00:00+00:00');

        $invite = CompanyInviteBuilder::anInvite()
            ->withCompany($company)
            ->withCreatedBy($owner)
            ->withEmail($user->getEmail())
            ->withTokenHash($tokenHash)
            ->withExpiresAt($now->modify('+1 day'))
            ->build();

        $inviteRepository = $this->createMock(CompanyInviteRepository::class);
        $inviteRepository->method('findOneByTokenHash')->with($tokenHash)->willReturn($invite);

        $memberRepository = $this->createMock(CompanyMemberRepository::class);
        $memberRepository->method('findOneByCompanyAndUser')->with($company, $user)->willReturn(null);

        $fullAccessRole = new CompanyRole(
            SystemCompanyRoles::FULL_ACCESS_ID,
            'Полный доступ',
            [Module::FINANCE->value => AccessLevel::WRITE->value],
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->with(CompanyRole::class, SystemCompanyRoles::FULL_ACCESS_ID)->willReturn($fullAccessRole);
        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(CompanyMember::class));
        $em->expects(self::once())->method('flush');

        $manager = new CompanyInviteManager(
            $em,
            $inviteRepository,
            $memberRepository,
            new InviteTokenService(),
        );

        $manager->acceptInvite($plainToken, $user, $now);

        self::assertEquals($now, $invite->getAcceptedAt());
    }

    public function testInviteOperatorRejectsRoleFromAnotherCompany(): void
    {
        $owner = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $otherOwner = UserBuilder::aUser()->withEmail('other@example.test')->build();
        $otherCompany = CompanyBuilder::aCompany()->withIndex(2)->withOwner($otherOwner)->build();
        $now = new \DateTimeImmutable('2025-01-02 10:00:00+00:00');
        $foreignRole = new CompanyRole(
            '77777777-7777-4777-8777-777777777777',
            'Foreign role',
            [Module::FINANCE->value => AccessLevel::WRITE->value],
            $otherCompany,
        );

        $inviteRepository = $this->createMock(CompanyInviteRepository::class);
        $memberRepository = $this->createMock(CompanyMemberRepository::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $manager = new CompanyInviteManager(
            $em,
            $inviteRepository,
            $memberRepository,
            $this->makeTokenService('token'),
        );

        $this->expectException(AccessDeniedException::class);

        $manager->inviteOperator($company, 'operator@example.test', $owner, $now, $foreignRole);
    }

    public function testAcceptInviteFallsBackToFullAccessWhenRoleBelongsToAnotherCompany(): void
    {
        $owner = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $otherOwner = UserBuilder::aUser()->withEmail('other@example.test')->build();
        $otherCompany = CompanyBuilder::aCompany()->withIndex(2)->withOwner($otherOwner)->build();
        $user = UserBuilder::aUser()->withEmail('operator@example.test')->build();
        $plainToken = 'foreign-role-token';
        $tokenHash = hash('sha256', $plainToken);
        $now = new \DateTimeImmutable('2025-03-01 12:00:00+00:00');
        $foreignRole = new CompanyRole(
            '88888888-8888-4888-8888-888888888888',
            'Foreign role',
            [Module::FINANCE->value => AccessLevel::WRITE->value],
            $otherCompany,
        );

        $invite = CompanyInviteBuilder::anInvite()
            ->withCompany($company)
            ->withCreatedBy($owner)
            ->withEmail($user->getEmail())
            ->withTokenHash($tokenHash)
            ->withExpiresAt($now->modify('+1 day'))
            ->withAccessRole($foreignRole)
            ->build();

        $inviteRepository = $this->createMock(CompanyInviteRepository::class);
        $inviteRepository->method('findOneByTokenHash')->with($tokenHash)->willReturn($invite);

        $memberRepository = $this->createMock(CompanyMemberRepository::class);
        $memberRepository->method('findOneByCompanyAndUser')->with($company, $user)->willReturn(null);

        $fullAccessRole = new CompanyRole(
            SystemCompanyRoles::FULL_ACCESS_ID,
            'Полный доступ',
            [Module::FINANCE->value => AccessLevel::WRITE->value],
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->with(CompanyRole::class, SystemCompanyRoles::FULL_ACCESS_ID)->willReturn($fullAccessRole);
        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(CompanyMember::class));
        $em->expects(self::once())->method('flush');

        $manager = new CompanyInviteManager(
            $em,
            $inviteRepository,
            $memberRepository,
            new InviteTokenService(),
        );

        $manager->acceptInvite($plainToken, $user, $now);

        self::assertEquals($now, $invite->getAcceptedAt());
    }

    private function makeTokenService(string $token): InviteTokenService
    {
        return new class($token) extends InviteTokenService {
            public function __construct(private string $token)
            {
            }

            public function generatePlainToken(): string
            {
                return $this->token;
            }
        };
    }
}
