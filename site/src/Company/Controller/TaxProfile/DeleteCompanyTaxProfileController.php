<?php

declare(strict_types=1);

namespace App\Company\Controller\TaxProfile;

use App\Company\Application\DeleteCompanyTaxProfileAction;
use App\Company\Entity\Company;
use App\Company\Exception\CompanyTaxProfileNotFoundException;
use App\Company\Infrastructure\Repository\CompanyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class DeleteCompanyTaxProfileController extends AbstractController
{
    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly DeleteCompanyTaxProfileAction $deleteTaxProfile,
    ) {
    }

    #[Route(
        '/company/{id}/tax-profile/{profileId}/delete',
        name: 'company_tax_profile_delete',
        methods: ['POST'],
        requirements: ['id' => '[0-9a-fA-F-]{36}', 'profileId' => '[0-9a-fA-F-]{36}'],
    )]
    public function __invoke(string $id, string $profileId, Request $request): Response
    {
        $company = $this->companyRepository->find($id);
        if (!$company instanceof Company || $company->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('delete_tax_profile'.$profileId, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Форма устарела, обновите страницу.');

            return $this->redirect($this->generateUrl('company_edit', ['id' => $id]).'#taxes');
        }

        try {
            ($this->deleteTaxProfile)($id, $profileId);
        } catch (CompanyTaxProfileNotFoundException) {
            throw $this->createNotFoundException();
        }

        $this->addFlash('success', 'Период налогового режима удалён.');

        return $this->redirect($this->generateUrl('company_edit', ['id' => $id]).'#taxes');
    }
}
