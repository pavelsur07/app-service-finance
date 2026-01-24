<?php

declare(strict_types=1);

namespace App\Loan\Controller;

use App\Loan\Repository\LoanPaymentScheduleRepository;
use App\Loan\Repository\LoanRepository;
use App\Sahred\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LoanReportController extends AbstractController
{
    #[Route('/loans/report', name: 'loan_report', methods: ['GET'])]
    public function report(
        LoanRepository $loanRepository,
        LoanPaymentScheduleRepository $paymentScheduleRepository,
        ActiveCompanyService $activeCompanyService,
    ): Response {
        $company = $activeCompanyService->getActiveCompany();
        $loans = $loanRepository->findActiveByCompany($company);

        $now = new \DateTimeImmutable('today');
        $yearStart = $now->setDate((int) $now->format('Y'), 1, 1)->setTime(0, 0, 0);
        $yearEnd = $now->setDate((int) $now->format('Y'), 12, 31)->setTime(23, 59, 59);

        $loansReport = [];
        foreach ($loans as $loan) {
            $nextPayment = $paymentScheduleRepository->findNextPaymentForLoan($loan, $now);

            $loansReport[] = [
                'name' => $loan->getName(),
                'remainingPrincipal' => $loan->getRemainingPrincipal(),
                'principalAmount' => $loan->getPrincipalAmount(),
                'interestRate' => $loan->getInterestRate(),
                'nextPaymentDate' => $nextPayment?->getDueDate(),
                'nextPaymentAmount' => $nextPayment?->getTotalPaymentAmount(),
                'totalInterestYear' => $paymentScheduleRepository->sumInterestForLoanAndPeriod(
                    $loan,
                    $yearStart,
                    $yearEnd
                ),
            ];
        }

        return $this->render('loan/report.html.twig', [
            'loansReport' => $loansReport,
            'year' => (int) $now->format('Y'),
        ]);
    }
}
