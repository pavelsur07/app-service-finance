<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company;

use App\Company\Entity\CompanyInvite;
use App\Company\Entity\CompanyMember;
use App\Company\Repository\CompanyInviteRepository;
use App\Company\Repository\CompanyMemberRepository;
use App\Company\Service\CompanyInviteManager;
use App\Company\Service\InviteTokenService;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyInviteBuilder;
use App\Tests\Builders\Company\UserBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

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
        self::assertSame($capturedInvite->getId(), $result->invite->getId());
        self::assertSame('operator@example.test', $capturedInvite->getEmail());
        self::assertSame(CompanyMember::ROLE_OPERATOR, $capturedInvite->getRole());
        self::assertSame($expectedHash, $capturedInvite->getTokenHash());
        self::assertSame($expectedExpiresAt, $capturedInvite->getExpiresAt());
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
        self::assertSame($invite->getId(), $result->invite->getId());
        self::assertSame($expectedHash, $invite->getTokenHash());
        self::assertSame($expectedExpiresAt, $invite->getExpiresAt());
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

        self::assertSame($now, $invite->getAcceptedAt());
        self::assertSame($user, $invite->getAcceptedByUser());
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
