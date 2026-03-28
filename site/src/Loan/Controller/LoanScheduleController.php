<?php

declare(strict_types=1);

namespace App\Loan\Controller;

use App\Loan\Entity\Loan;
use App\Loan\Repository\LoanPaymentScheduleRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LoanScheduleController extends AbstractController
{
    #[Route('/loans/{id}/schedule', name: 'loan_schedule', methods: ['GET'])]
    public function __invoke(
        Loan $loan,
        ActiveCompanyService $activeCompanyService,
        LoanPaymentScheduleRepository $paymentScheduleRepository,
    ): Response {
        $company = $activeCompanyService->getActiveCompany();
        if ($loan->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        return $this->render('loan/schedule.html.twig', [
            'loan' => $loan,
            'scheduleItems' => $paymentScheduleRepository->findByLoanOrderedByDueDate($loan),
        ]);
    }
}
