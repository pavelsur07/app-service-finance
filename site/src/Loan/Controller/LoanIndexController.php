<?php

declare(strict_types=1);

namespace App\Loan\Controller;

use App\Loan\Repository\LoanRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class LoanIndexController extends AbstractController
{
    #[Route('/loans', name: 'loan_index', methods: ['GET'])]
    public function __invoke(LoanRepository $loanRepository, ActiveCompanyService $activeCompanyService): Response
    {
        $company = $activeCompanyService->getActiveCompany();
        $loans = $loanRepository->findBy(
            ['company' => $company],
            ['startDate' => 'DESC', 'createdAt' => 'DESC'],
        );

        return $this->render('loan/index.html.twig', [
            'loans' => $loans,
        ]);
    }
}
