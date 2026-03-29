<?php

declare(strict_types=1);

namespace App\Loan\Controller;

use App\Finance\Facade\PLCategoryFacade;
use App\Loan\Application\UpdateLoanAction;
use App\Loan\Entity\Loan;
use App\Loan\Form\LoanType;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class LoanEditController extends AbstractController
{
    #[Route('/loans/{id}/edit', name: 'loan_edit', methods: ['GET', 'POST'])]
    public function __invoke(
        Loan $loan,
        Request $request,
        ActiveCompanyService $activeCompanyService,
        PLCategoryFacade $plCategoryFacade,
        UpdateLoanAction $updateLoanAction,
    ): Response {
        $company = $activeCompanyService->getActiveCompany();
        if ($loan->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $categories = $plCategoryFacade->findTreeEntitiesByCompanyId((string) $company->getId());
        $form = $this->createForm(LoanType::class, $loan, ['categories' => $categories]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            ($updateLoanAction)($loan);

            return $this->redirectToRoute('loan_index');
        }

        return $this->render('loan/form.html.twig', [
            'form' => $form->createView(),
            'loan' => $loan,
            'is_edit' => true,
        ]);
    }
}
