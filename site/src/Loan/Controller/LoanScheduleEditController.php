<?php

declare(strict_types=1);

namespace App\Loan\Controller;

use App\Loan\Application\UpdateLoanScheduleItemAction;
use App\Loan\Entity\LoanPaymentSchedule;
use App\Loan\Form\LoanPaymentScheduleType;
use App\Loan\Repository\LoanPaymentScheduleRepository;
use App\Loan\Repository\LoanRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class LoanScheduleEditController extends AbstractController
{
    #[Route('/loans/{loanId}/schedule/{itemId}/edit', name: 'loan_schedule_edit', methods: ['GET', 'POST'])]
    public function __invoke(
        string $loanId,
        string $itemId,
        Request $request,
        LoanRepository $loanRepository,
        LoanPaymentScheduleRepository $paymentScheduleRepository,
        ActiveCompanyService $activeCompanyService,
        UpdateLoanScheduleItemAction $updateAction,
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

        $form = $this->createForm(LoanPaymentScheduleType::class, $schedule);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            ($updateAction)($schedule);

            return $this->redirectToRoute('loan_schedule', ['id' => $loan->getId()]);
        }

        return $this->render('loan/schedule.html.twig', [
            'loan' => $loan,
            'scheduleItems' => $paymentScheduleRepository->findByLoanOrderedByDueDate($loan),
            'form' => $form->createView(),
            'formTitle' => 'Редактирование платежа',
        ]);
    }
}
