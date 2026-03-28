<?php

declare(strict_types=1);

namespace App\Loan\Controller;

use App\Loan\Application\DeleteLoanScheduleItemAction;
use App\Loan\Entity\LoanPaymentSchedule;
use App\Loan\Repository\LoanPaymentScheduleRepository;
use App\Loan\Repository\LoanRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LoanScheduleDeleteController extends AbstractController
{
    #[Route('/loans/{loanId}/schedule/{itemId}/delete', name: 'loan_schedule_delete', methods: ['POST'])]
    public function __invoke(
        string $loanId,
        string $itemId,
        Request $request,
        LoanRepository $loanRepository,
        LoanPaymentScheduleRepository $paymentScheduleRepository,
        ActiveCompanyService $activeCompanyService,
        DeleteLoanScheduleItemAction $deleteAction,
    ): Response {
        $company = $activeCompanyService->getActiveCompany();
        $loan = $loanRepository->find($loanId);

        if (null === $loan || $loan->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $schedule = $paymentScheduleRepository->find($itemId);
        if (!$schedule instanceof LoanPaymentSchedule || $schedule->getLoan()?->getId() !== $loan->getId()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('loan_schedule_delete_'.$schedule->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        ($deleteAction)($schedule);

        return $this->redirectToRoute('loan_schedule', ['id' => $loan->getId()]);
    }
}
