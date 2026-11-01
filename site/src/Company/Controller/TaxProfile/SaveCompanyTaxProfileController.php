<?php

declare(strict_types=1);

namespace App\Company\Controller\TaxProfile;

use App\Company\Application\SaveCompanyTaxProfileAction;
use App\Company\Entity\Company;
use App\Company\Enum\TaxRegime;
use App\Company\Enum\VatMode;
use App\Company\Exception\InvalidCompanyTaxProfileException;
use App\Company\Form\CompanyTaxProfileType;
use App\Company\Infrastructure\Repository\CompanyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class SaveCompanyTaxProfileController extends AbstractController
{
    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly SaveCompanyTaxProfileAction $saveTaxProfile,
    ) {
    }

    #[Route('/company/{id}/tax-profile', name: 'company_tax_profile_save', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function __invoke(string $id, Request $request): Response
    {
        // Как и карточка компании — только владелец.
        $company = $this->companyRepository->find($id);
        if (!$company instanceof Company || $company->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(CompanyTaxProfileType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('danger', $error->getMessage());
            }

            return $this->redirectToTaxes($id);
        }

        /** @var array{taxRegime: TaxRegime, vatMode: VatMode, validFrom: ?string} $data */
        $data = $form->getData();

        try {
            ($this->saveTaxProfile)($id, $data['taxRegime'], $data['vatMode'], CompanyTaxProfileType::parseValidFrom($data['validFrom']));
        } catch (InvalidCompanyTaxProfileException $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToTaxes($id);
        }

        $this->addFlash('success', 'Налоговый режим сохранён.');

        return $this->redirectToTaxes($id);
    }

    private function redirectToTaxes(string $companyId): Response
    {
        return $this->redirect($this->generateUrl('company_edit', ['id' => $companyId]).'#taxes');
    }
}
