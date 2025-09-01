<?php

namespace App\Service;

use App\Entity\Company;
use App\Repository\CompanyRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ActiveCompanyService
{
    public function __construct(
        private RequestStack $requestStack,
        private CompanyRepository $companyRepository,
        private Security $security
    ) {
    }

    public function getActiveCompany(): Company
    {
        $id = $this->requestStack->getSession()->get('active_company_id');
        $company = $this->companyRepository->find($id);
        $user = $this->security->getUser();
        if (!$company || $company->getUser() !== $user) {
            throw new NotFoundHttpException();
        }
        return $company;
    }
}
