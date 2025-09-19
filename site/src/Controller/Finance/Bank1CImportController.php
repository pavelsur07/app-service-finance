<?php

namespace App\Controller\Finance;

use App\Repository\MoneyAccountRepository;
use App\Service\ActiveCompanyService;
use App\Service\Bank1C\Bank1CImportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/finance/imports/bank-1c')]
class Bank1CImportController extends AbstractController
{
    public function __construct(private ActiveCompanyService $companyService)
    {
    }

    #[Route('', name: 'bank1c_import_form', methods: ['GET'])]
    public function form(MoneyAccountRepository $accountRepo): Response
    {
        $company = $this->companyService->getActiveCompany();
        $accounts = $accountRepo->findBy(['company' => $company]);

        return $this->render('finance/import/bank1c/upload.html.twig', [
            'accounts' => $accounts,
        ]);
    }

    #[Route('', name: 'bank1c_import_handle', methods: ['POST'])]
    public function handle(
        Request $request,
        MoneyAccountRepository $accountRepo,
        Bank1CImportService $importService,
    ): Response {
        if (!$this->isCsrfTokenValid('bank1c_import', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $company = $this->companyService->getActiveCompany();
        $accountId = $request->request->get('moneyAccount');
        $account = $accountRepo->find($accountId);
        if (!$account || $account->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }
        /** @var UploadedFile|null $file */
        $file = $request->files->get('statement');
        if (!$file) {
            $this->addFlash('danger', 'Файл не загружен');

            return $this->redirectToRoute('bank1c_import_form');
        }
        $extension = strtolower($file->getClientOriginalExtension() ?? '');
        if ($file->getSize() > 10 * 1024 * 1024 || 'txt' !== $extension) {
            $this->addFlash('danger', 'Недопустимый файл');

            return $this->redirectToRoute('bank1c_import_form');
        }
        $raw = file_get_contents($file->getPathname());
        $result = $importService->import($company, $account, $raw, $file->getClientOriginalName());

        return $this->render('finance/import/bank1c/result.html.twig', [
            'result' => $result,
        ]);
    }
}
