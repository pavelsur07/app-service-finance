<?php

namespace App\Company\Service;

use App\Company\DTO\CompanyInviteResult;
use App\Company\Entity\Company;
use App\Company\Entity\CompanyInvite;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\User;
use App\Company\Repository\CompanyInviteRepository;
use App\Company\Repository\CompanyMemberRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
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
        private UserRepository $userRepository,
        private InviteTokenService $tokenService,
    ) {
    }

    public function inviteOperator(
        Company $company,
        string $email,
        User $actor,
        \DateTimeImmutable $now = null,
    ): CompanyInviteResult {
        $this->assertOwner($company, $actor);

        $normalizedEmail = \mb_strtolower(\trim($email));
        $now = $now ?? new \DateTimeImmutable();

        $existingUser = $this->userRepository->findOneBy(['email' => $normalizedEmail]);
        if ($existingUser instanceof User) {
            $member = $this->memberRepository->findOneByCompanyAndUser($company, $existingUser);
            if (!$member) {
                $member = new CompanyMember(
                    id: Uuid::uuid4()->toString(),
                    company: $company,
                    user: $existingUser,
                    role: CompanyMember::ROLE_OPERATOR,
                    createdAt: $now,
                );
                $this->em->persist($member);
                $this->em->flush();
            }

            return new CompanyInviteResult(
                type: 'member_created',
                member: $member,
            );
        }

        $plainToken = $this->tokenService->generatePlainToken();
        $tokenHash = $this->tokenService->hashToken($plainToken);
        $expiresAt = $now->modify(sprintf('+%d hours', self::INVITE_TTL_HOURS));

        $invite = $this->inviteRepository->findPendingByCompanyAndEmail($company, $normalizedEmail, $now);
        if ($invite) {
            $invite->renewToken($tokenHash, $expiresAt);
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
        \DateTimeImmutable $now = null,
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

        if ($invite->getEmail() !== $user->getEmail()) {
            throw new AccessDeniedException('Invite email does not match user.');
        }

        $member = $this->memberRepository->findOneByCompanyAndUser($invite->getCompany(), $user);
        if (!$member) {
            $member = new CompanyMember(
                id: Uuid::uuid4()->toString(),
                company: $invite->getCompany(),
                user: $user,
                role: $invite->getRole(),
                createdAt: $now,
            );
            $this->em->persist($member);
        }

        $invite->accept($user, $now);
        $this->em->flush();
    }

    public function revokeInvite(
        CompanyInvite $invite,
        User $actor,
        \DateTimeImmutable $now = null,
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
}
