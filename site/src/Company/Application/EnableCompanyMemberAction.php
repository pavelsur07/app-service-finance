<?php

declare(strict_types=1);

namespace App\Company\Application;

use App\Company\Entity\User;
use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Company\Repository\CompanyMemberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class EnableCompanyMemberAction
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

        $member->enable();
        $this->entityManager->flush();
    }
}
