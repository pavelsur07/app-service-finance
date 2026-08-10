<?php

namespace App\Company\Service;

use App\Company\DTO\CompanyInviteResult;
use App\Company\Entity\Company;
use App\Company\Entity\CompanyInvite;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
use App\Company\Entity\User;
use App\Company\Repository\CompanyInviteRepository;
use App\Company\Repository\CompanyMemberRepository;
use App\Company\Security\SystemCompanyRoles;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class CompanyInviteManager
{
    private const INVITE_TTL_HOURS = 72;

    public function __construct(
        private EntityManagerInterface $em,
        private CompanyInviteRepository $inviteRepository,
        private CompanyMemberRepository $memberRepository,
        private InviteTokenService $tokenService,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function inviteOperator(
        Company $company,
        string $email,
        User $actor,
        ?\DateTimeImmutable $now = null,
        ?CompanyRole $accessRole = null,
    ): CompanyInviteResult {
        $this->assertOwner($company, $actor);
        $this->assertRoleBelongsToCompany($accessRole, $company);

        $normalizedEmail = \mb_strtolower(\trim($email));
        $now = $now ?? new \DateTimeImmutable();

        $plainToken = $this->tokenService->generatePlainToken();
        $tokenHash = $this->tokenService->hashToken($plainToken);
        $expiresAt = $now->modify(sprintf('+%d hours', self::INVITE_TTL_HOURS));

        $invite = $this->inviteRepository->findPendingByCompanyAndEmail($company, $normalizedEmail, $now);
        if ($invite) {
            $invite->renewToken($tokenHash, $expiresAt);
            $invite->setAccessRole($accessRole);
            $this->em->flush();

            return new CompanyInviteResult(
                type: 'invite_renewed',
                invite: $invite,
                plainToken: $plainToken,
            );
        }

        $invite = new CompanyInvite(
            id: Uuid::uuid4()->toString(),
            company: $company,
            createdBy: $actor,
            email: $normalizedEmail,
            role: CompanyMember::ROLE_OPERATOR,
            tokenHash: $tokenHash,
            expiresAt: $expiresAt,
            createdAt: $now,
            accessRole: $accessRole,
        );
        $this->em->persist($invite);
        $this->em->flush();

        return new CompanyInviteResult(
            type: 'invite_created',
            invite: $invite,
            plainToken: $plainToken,
        );
    }

    public function acceptInvite(
        string $plainToken,
        User $user,
        ?\DateTimeImmutable $now = null,
    ): void {
        $now = $now ?? new \DateTimeImmutable();
        $tokenHash = $this->tokenService->hashToken($plainToken);

        $invite = $this->inviteRepository->findOneByTokenHash($tokenHash);
        if (!$invite) {
            throw new NotFoundHttpException('Invite not found.');
        }

        if (!$invite->isPending($now)) {
            throw new GoneHttpException('Invite is not pending.');
        }

        $userEmail = \mb_strtolower($user->getEmail() ?? '');
        if ($invite->getEmail() !== $userEmail) {
            throw new AccessDeniedException('Invite email does not match user.');
        }

        $member = $this->memberRepository->findOneByCompanyAndUser($invite->getCompany(), $user);
        if ($member && CompanyMember::STATUS_DISABLED === $member->getStatus()) {
            throw new AccessDeniedException('Company member is disabled.');
        }

        $isNewMember = false;
        if (!$member) {
            $isNewMember = true;
            $member = new CompanyMember(
                id: Uuid::uuid4()->toString(),
                company: $invite->getCompany(),
                user: $user,
                role: $invite->getRole(),
                createdAt: $now,
            );
            $this->em->persist($member);
        }

        if ($isNewMember) {
            $accessRole = $this->resolveAccessRoleForNewMember($invite);
            $member->setAccessRole($accessRole);
        }

        $invite->accept($user, $now);
        $this->em->flush();
    }

    public function revokeInvite(
        CompanyInvite $invite,
        User $actor,
        ?\DateTimeImmutable $now = null,
    ): void {
        $this->assertOwner($invite->getCompany(), $actor);
        $invite->revoke($now ?? new \DateTimeImmutable());
        $this->em->flush();
    }

    private function assertOwner(Company $company, User $actor): void
    {
        if ($company->getUser() !== $actor) {
            throw new AccessDeniedException('Only the company owner can manage invites.');
        }
    }

    private function assertRoleBelongsToCompany(?CompanyRole $role, Company $company): void
    {
        if (!$role instanceof CompanyRole) {
            return;
        }

        $roleCompany = $role->getCompany();
        if (null !== $roleCompany && (string) $roleCompany->getId() !== (string) $company->getId()) {
            throw new AccessDeniedException('Access role does not belong to company.');
        }
    }

    private function resolveAccessRoleForNewMember(CompanyInvite $invite): ?CompanyRole
    {
        $accessRole = $invite->getAccessRole();
        $company = $invite->getCompany();

        if (!$accessRole instanceof CompanyRole) {
            return $this->em->find(CompanyRole::class, SystemCompanyRoles::FULL_ACCESS_ID);
        }

        $roleCompany = $accessRole->getCompany();
        if (null === $roleCompany || (string) $roleCompany->getId() === (string) $company->getId()) {
            return $accessRole;
        }

        $this->logger?->warning('Invite access role does not belong to company; falling back to Full Access.', [
            'companyId' => (string) $company->getId(),
            'inviteId' => (string) $invite->getId(),
            'roleId' => (string) $accessRole->getId(),
        ]);

        return $this->em->find(CompanyRole::class, SystemCompanyRoles::FULL_ACCESS_ID);
    }
}
