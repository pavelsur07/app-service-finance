<?php

namespace App\Sahred\Service;

use App\Entity\Company;
use App\Repository\CompanyRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ActiveCompanyService
{
    public function __construct(
        private RequestStack $requestStack,
        private CompanyRepository $companyRepository,
        private Security $security,
    ) {
    }

    public function getActiveCompany(): Company
    {
        $session = $this->requestStack->getSession();
        $id = $session->get('active_company_id');
        $user = $this->security->getUser();

        if ($id) {
            $company = $this->companyRepository->find($id);
            if ($company && $company->getUser() === $user) {
                return $company;
            }
        }

        $company = $this->companyRepository->findOneBy(['user' => $user]);
        if (!$company) {
            throw new NotFoundHttpException();
        }

        $session->set('active_company_id', $company->getId());

        return $company;
    }
}
