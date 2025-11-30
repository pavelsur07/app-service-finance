<?php

declare(strict_types=1);

namespace App\Loan\Controller;

use App\Loan\Entity\Loan;
use App\Loan\Entity\LoanPaymentSchedule;
use App\Loan\Form\LoanType;
use App\Loan\Form\LoanPaymentScheduleType;
use App\Loan\Form\LoanScheduleUploadType;
use App\Loan\Repository\LoanPaymentScheduleRepository;
use App\Loan\Repository\LoanRepository;
use App\Loan\Service\LoanScheduleToDocumentService;
use App\Repository\PLCategoryRepository;
use App\Service\ActiveCompanyService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use SplFileObject;

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
    public function create(
        Request $request,
        EntityManagerInterface $entityManager,
        ActiveCompanyService $activeCompanyService,
        PLCategoryRepository $plCategoryRepository,
    ): Response {
        $company = $activeCompanyService->getActiveCompany();
        $loan = new Loan($company, '', '0.00', new DateTimeImmutable());
        $categories = $plCategoryRepository->findTreeByCompany($company);
        $form = $this->createForm(LoanType::class, $loan, [
            'categories' => $categories,
        ]);
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
    public function edit(
        Loan $loan,
        Request $request,
        EntityManagerInterface $entityManager,
        ActiveCompanyService $activeCompanyService,
        PLCategoryRepository $plCategoryRepository,
    ): Response {
        $company = $activeCompanyService->getActiveCompany();
        if ($loan->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $categories = $plCategoryRepository->findTreeByCompany($company);
        $form = $this->createForm(LoanType::class, $loan, [
            'categories' => $categories,
        ]);
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

    #[Route('/{id}/schedule/upload', name: 'loan_schedule_upload', methods: ['GET', 'POST'])]
    public function uploadSchedule(
        string $id,
        Request $request,
        LoanRepository $loanRepository,
        LoanPaymentScheduleRepository $paymentScheduleRepository,
        EntityManagerInterface $entityManager,
        ActiveCompanyService $activeCompanyService
    ): Response {
        $company = $activeCompanyService->getActiveCompany();
        $loan = $loanRepository->find($id);

        if (null === $loan || $loan->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(LoanScheduleUploadType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile|null $file */
            $file = $form->get('file')->getData();

            if ($file instanceof UploadedFile) {
                $csv = new SplFileObject($file->getPathname());
                $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
                $csv->setCsvControl(';');

                foreach ($paymentScheduleRepository->findByLoanOrderedByDueDate($loan) as $item) {
                    $entityManager->remove($item);
                }

                $isHeaderSkipped = false;

                foreach ($csv as $row) {
                    if (!$isHeaderSkipped) {
                        $isHeaderSkipped = true;
                        continue;
                    }

                    if (!is_array($row) || count($row) < 6 || null === $row[0]) {
                        continue;
                    }

                    [$date, $totalPayment, $principalPart, $interestPart, $feePart, $isPaid] = $row;

                    $dueDate = DateTimeImmutable::createFromFormat('Y-m-d', (string) $date);
                    if (false === $dueDate) {
                        continue;
                    }

                    $schedule = new LoanPaymentSchedule(
                        $loan,
                        $dueDate,
                        (string) $totalPayment,
                        (string) $principalPart,
                        (string) $interestPart,
                        (string) $feePart
                    );

                    $schedule->setIsPaid('1' === trim((string) $isPaid));
                    $entityManager->persist($schedule);
                }

                $entityManager->flush();

                return $this->redirectToRoute('loan_schedule', ['id' => $loan->getId()]);
            }
        }

        return $this->render('loan/schedule_upload.html.twig', [
            'loan' => $loan,
            'uploadForm' => $form->createView(),
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

    #[Route('/schedule/template', name: 'loan_schedule_template', methods: ['GET'])]
    public function downloadScheduleTemplate(): StreamedResponse
    {
        $response = new StreamedResponse();
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="loan_schedule_template.csv"');

        $response->setCallback(function () {
            $handle = fopen('php://output', 'wb');

            $rows = [
                ['date', 'total_payment', 'principal_part', 'interest_part', 'fee_part', 'is_paid'],
                ['2025-01-28', '50000.00', '35000.00', '15000.00', '0.00', '0'],
                ['2025-02-28', '50000.00', '36000.00', '14000.00', '0.00', '0'],
                ['2025-03-28', '50000.00', '37000.00', '13000.00', '0.00', '0'],
            ];

            foreach ($rows as $row) {
                fputcsv($handle, $row, ';');
            }

            fclose($handle);
        });

        return $response;
    }

    #[Route(
        '/{loanId}/schedule/{itemId}/create-document',
        name: 'loan_schedule_create_document',
        methods: ['POST']
    )]
    public function createDocumentFromSchedule(
        string $loanId,
        string $itemId,
        Request $request,
        LoanRepository $loanRepository,
        LoanPaymentScheduleRepository $paymentScheduleRepository,
        LoanScheduleToDocumentService $scheduleToDocumentService,
        ActiveCompanyService $activeCompanyService,
        EntityManagerInterface $entityManager
    ): Response {
        $company = $activeCompanyService->getActiveCompany();
        $loan = $loanRepository->find($loanId);

        if (null === $loan || $loan->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $schedule = $paymentScheduleRepository->find($itemId);
        if (null === $schedule || $schedule->getLoan() !== $loan) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('loan_schedule_create_document_'.$itemId, (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('loan_schedule', ['id' => $loan->getId()]);
        }

        $document = $scheduleToDocumentService->createDocumentFromSchedule($loan, $schedule);

        $entityManager->persist($document);
        $entityManager->flush();

        return $this->redirectToRoute('document_edit', ['id' => $document->getId()]);
    }
}
