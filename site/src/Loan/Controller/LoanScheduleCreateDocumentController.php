<?php

declare(strict_types=1);

namespace App\Loan\Controller;

use App\Loan\Application\CreateDocumentFromLoanScheduleAction;
use App\Loan\Entity\LoanPaymentSchedule;
use App\Loan\Repository\LoanPaymentScheduleRepository;
use App\Loan\Repository\LoanRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class LoanScheduleCreateDocumentController extends AbstractController
{
    #[Route(
        '/loans/{loanId}/schedule/{itemId}/create-document',
        name: 'loan_schedule_create_document',
        methods: ['POST']
    )]
    public function __invoke(
        string $loanId,
        string $itemId,
        Request $request,
        LoanRepository $loanRepository,
        LoanPaymentScheduleRepository $paymentScheduleRepository,
        ActiveCompanyService $activeCompanyService,
        CreateDocumentFromLoanScheduleAction $createDocumentAction,
    ): Response {
        $company = $activeCompanyService->getActiveCompany();
        $loan = $loanRepository->find($loanId);

        if (null === $loan || $loan->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $schedule = $paymentScheduleRepository->find($itemId);
        if (!$schedule instanceof LoanPaymentSchedule || $schedule->getLoan() !== $loan) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('loan_schedule_create_document_'.$itemId, (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('loan_schedule', ['id' => $loan->getId()]);
        }

        $documentId = ($createDocumentAction)($loan, $schedule);

        return $this->redirectToRoute('document_edit', ['id' => $documentId]);
    }
}
