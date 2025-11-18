<?php

declare(strict_types=1);

namespace App\Loan\Controller;

use App\Loan\Entity\Loan;
use App\Loan\Entity\LoanPaymentSchedule;
use App\Loan\Form\LoanType;
use App\Loan\Form\LoanPaymentScheduleType;
use App\Loan\Repository\LoanPaymentScheduleRepository;
use App\Loan\Repository\LoanRepository;
use App\Service\ActiveCompanyService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/loans')]
class LoanController extends AbstractController
{
    #[Route('', name: 'loan_index', methods: ['GET'])]
    public function index(LoanRepository $loanRepository, ActiveCompanyService $activeCompanyService): Response
    {
        $company = $activeCompanyService->getActiveCompany();
        $loans = $loanRepository->findBy([
            'company' => $company,
        ], [
            'startDate' => 'DESC',
            'createdAt' => 'DESC',
        ]);

        return $this->render('loan/index.html.twig', [
            'loans' => $loans,
        ]);
    }

    #[Route('/create', name: 'loan_create', methods: ['GET', 'POST'])]
    public function create(Request $request, EntityManagerInterface $entityManager, ActiveCompanyService $activeCompanyService): Response
    {
        $company = $activeCompanyService->getActiveCompany();
        $loan = new Loan($company, '', '0.00', new DateTimeImmutable());
        $form = $this->createForm(LoanType::class, $loan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $loan->setUpdatedAt(new DateTimeImmutable());
            $entityManager->persist($loan);
            $entityManager->flush();

            return $this->redirectToRoute('loan_index');
        }

        return $this->render('loan/form.html.twig', [
            'form' => $form->createView(),
            'loan' => $loan,
            'is_edit' => false,
        ]);
    }

    #[Route('/{id}/edit', name: 'loan_edit', methods: ['GET', 'POST'])]
    public function edit(Loan $loan, Request $request, EntityManagerInterface $entityManager, ActiveCompanyService $activeCompanyService): Response
    {
        $company = $activeCompanyService->getActiveCompany();
        if ($loan->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(LoanType::class, $loan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $loan->setUpdatedAt(new DateTimeImmutable());
            $entityManager->flush();

            return $this->redirectToRoute('loan_index');
        }

        return $this->render('loan/form.html.twig', [
            'form' => $form->createView(),
            'loan' => $loan,
            'is_edit' => true,
        ]);
    }

    #[Route('/{id}/schedule', name: 'loan_schedule', methods: ['GET'])]
    public function schedule(
        Loan $loan,
        ActiveCompanyService $activeCompanyService,
        LoanPaymentScheduleRepository $paymentScheduleRepository
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

    #[Route('/{id}/schedule/add', name: 'loan_schedule_add', methods: ['GET', 'POST'])]
    public function scheduleAdd(
        Loan $loan,
        Request $request,
        EntityManagerInterface $entityManager,
        ActiveCompanyService $activeCompanyService,
        LoanPaymentScheduleRepository $paymentScheduleRepository
    ): Response {
        $company = $activeCompanyService->getActiveCompany();
        if ($loan->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $schedule = new LoanPaymentSchedule($loan, new DateTimeImmutable(), '0.00', '0.00', '0.00', '0.00');
        $form = $this->createForm(LoanPaymentScheduleType::class, $schedule);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $schedule->setUpdatedAt(new DateTimeImmutable());
            $loan->addPaymentScheduleItem($schedule);
            $entityManager->persist($schedule);
            $entityManager->flush();

            return $this->redirectToRoute('loan_schedule', ['id' => $loan->getId()]);
        }

        return $this->render('loan/schedule.html.twig', [
            'loan' => $loan,
            'scheduleItems' => $paymentScheduleRepository->findByLoanOrderedByDueDate($loan),
            'form' => $form->createView(),
            'formTitle' => 'Новый платеж графика',
        ]);
    }

    #[Route('/{loanId}/schedule/{itemId}/edit', name: 'loan_schedule_edit', methods: ['GET', 'POST'])]
    public function scheduleEdit(
        string $loanId,
        string $itemId,
        Request $request,
        LoanRepository $loanRepository,
        LoanPaymentScheduleRepository $paymentScheduleRepository,
        EntityManagerInterface $entityManager,
        ActiveCompanyService $activeCompanyService
    ): Response {
        $company = $activeCompanyService->getActiveCompany();
        $loan = $loanRepository->find($loanId);

        if (null === $loan || $loan->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $schedule = $paymentScheduleRepository->find($itemId);
        if (null === $schedule || $schedule->getLoan()?->getId() !== $loan->getId()) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(LoanPaymentScheduleType::class, $schedule);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $schedule->setUpdatedAt(new DateTimeImmutable());
            $entityManager->flush();

            return $this->redirectToRoute('loan_schedule', ['id' => $loan->getId()]);
        }

        return $this->render('loan/schedule.html.twig', [
            'loan' => $loan,
            'scheduleItems' => $paymentScheduleRepository->findByLoanOrderedByDueDate($loan),
            'form' => $form->createView(),
            'formTitle' => 'Редактирование платежа',
        ]);
    }

    #[Route('/{loanId}/schedule/{itemId}/delete', name: 'loan_schedule_delete', methods: ['POST'])]
    public function scheduleDelete(
        string $loanId,
        string $itemId,
        Request $request,
        LoanRepository $loanRepository,
        LoanPaymentScheduleRepository $paymentScheduleRepository,
        EntityManagerInterface $entityManager,
        ActiveCompanyService $activeCompanyService
    ): Response {
        $company = $activeCompanyService->getActiveCompany();
        $loan = $loanRepository->find($loanId);

        if (null === $loan || $loan->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $schedule = $paymentScheduleRepository->find($itemId);
        if (null === $schedule || $schedule->getLoan()?->getId() !== $loan->getId()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('loan_schedule_delete_'.$schedule->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $entityManager->remove($schedule);
        $entityManager->flush();

        return $this->redirectToRoute('loan_schedule', ['id' => $loan->getId()]);
    }
}
