<?php

declare(strict_types=1);

namespace App\Loan\Controller;

use App\Loan\Application\UploadLoanScheduleAction;
use App\Loan\Entity\Loan;
use App\Loan\Form\LoanScheduleUploadType;
use App\Loan\Repository\LoanRepository;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class LoanScheduleUploadController extends AbstractController
{
    #[Route('/loans/{id}/schedule/upload', name: 'loan_schedule_upload', methods: ['GET', 'POST'])]
    public function __invoke(
        string $id,
        Request $request,
        LoanRepository $loanRepository,
        ActiveCompanyService $activeCompanyService,
        UploadLoanScheduleAction $uploadAction,
    ): Response {
        $company = $activeCompanyService->getActiveCompany();

        // Один экшен на GET и POST: read покрыт ModuleAccessSubscriber, write гейтим здесь.
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }

        $loan = $loanRepository->find($id);

        if (!$loan instanceof Loan || $loan->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(LoanScheduleUploadType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile|null $file */
            $file = $form->get('file')->getData();

            if ($file instanceof UploadedFile) {
                $csv = new \SplFileObject($file->getPathname());
                ($uploadAction)($loan, $csv);

                return $this->redirectToRoute('loan_schedule', ['id' => $loan->getId()]);
            }
        }

        return $this->render('loan/schedule_upload.html.twig', [
            'loan' => $loan,
            'uploadForm' => $form->createView(),
        ]);
    }
}
