<?php

namespace App\Shared\Service;

use App\Company\Entity\Company;
use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Company\Repository\CompanyMemberRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ActiveCompanyService
{
    public function __construct(
        private RequestStack $requestStack,
        private CompanyRepository $companyRepository,
        private CompanyMemberRepository $companyMemberRepository,
        private Security $security,
    ) {
    }

    public function getActiveCompany(): Company
    {
        $session = $this->requestStack->getSession();
        $id = $session->get('active_company_id');
        $user = $this->security->getUser();

        if (null === $user) {
            throw new NotFoundHttpException();
        }

        if ($id) {
            $company = $this->companyRepository->find($id);
            if (
                $company
                && (
                    $company->getUser() === $user
                    || null !== $this->companyMemberRepository->findActiveOneByCompanyAndUser($company, $user)
                )
            ) {
                return $company;
            }
        }

        $company = $this->companyRepository->findOneBy(['user' => $user])
            ?? $this->companyMemberRepository->findFirstActiveCompanyForUser($user);
        if (!$company) {
            throw new NotFoundHttpException();
        }

        $session->set('active_company_id', $company->getId());

        return $company;
    }
}
