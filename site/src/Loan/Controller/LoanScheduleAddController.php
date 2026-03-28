<?php

declare(strict_types=1);

namespace App\Loan\Controller;

use App\Loan\Application\AddLoanScheduleItemAction;
use App\Loan\Entity\Loan;
use App\Loan\Entity\LoanPaymentSchedule;
use App\Loan\Form\LoanPaymentScheduleType;
use App\Loan\Repository\LoanPaymentScheduleRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LoanScheduleAddController extends AbstractController
{
    #[Route('/loans/{id}/schedule/add', name: 'loan_schedule_add', methods: ['GET', 'POST'])]
    public function __invoke(
        Loan $loan,
        Request $request,
        ActiveCompanyService $activeCompanyService,
        LoanPaymentScheduleRepository $paymentScheduleRepository,
        AddLoanScheduleItemAction $addAction,
    ): Response {
        $company = $activeCompanyService->getActiveCompany();
        if ($loan->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $schedule = new LoanPaymentSchedule($loan, new \DateTimeImmutable(), '0.00', '0.00', '0.00', '0.00');
        $form = $this->createForm(LoanPaymentScheduleType::class, $schedule);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            ($addAction)($loan, $schedule);

            return $this->redirectToRoute('loan_schedule', ['id' => $loan->getId()]);
        }

        return $this->render('loan/schedule.html.twig', [
            'loan' => $loan,
            'scheduleItems' => $paymentScheduleRepository->findByLoanOrderedByDueDate($loan),
            'form' => $form->createView(),
            'formTitle' => 'Новый платеж графика',
        ]);
    }
}
