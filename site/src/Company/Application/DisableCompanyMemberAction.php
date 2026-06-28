<?php

declare(strict_types=1);

namespace App\Company\Application;

use App\Company\Entity\CompanyMember;
use App\Company\Entity\User;
use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Company\Repository\CompanyMemberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class DisableCompanyMemberAction
{
    public function __construct(
        private CompanyRepository $companyRepository,
        private CompanyMemberRepository $companyMemberRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(string $companyId, string $memberId, User $actor): void
    {
        $company = $this->companyRepository->findById($companyId);
        if (null === $company) {
            throw new NotFoundHttpException('Company not found.');
        }

        if ($company->getUser()->getId() !== $actor->getId()) {
            throw new AccessDeniedException('Only company owner can manage members.');
        }

        $member = $this->companyMemberRepository->findOneByIdAndCompanyId($memberId, $companyId);
        if (null === $member) {
            throw new NotFoundHttpException('Company member not found.');
        }

        $memberUserId = $member->getUser()->getId();

        if ($memberUserId === $actor->getId()) {
            throw new AccessDeniedException('Company owner cannot disable own membership.');
        }

        if ($memberUserId === $company->getUser()->getId() || CompanyMember::ROLE_OWNER === $member->getRole()) {
            throw new AccessDeniedException('Company owner membership cannot be disabled.');
        }

        $member->disable();
        $this->entityManager->flush();
    }
}
