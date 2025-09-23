<?php

namespace App\Controller\Cash;

use App\Repository\MoneyAccountRepository;
use App\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/cash/import/bank1c')]
class Bank1CImportController extends AbstractController
{
    public function __construct(private readonly ActiveCompanyService $activeCompanyService)
    {
    }

    #[Route('', name: 'cash_bank1c_import_upload', methods: ['GET'])]
    public function upload(MoneyAccountRepository $accountRepository): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $accounts = $accountRepository->findBy(['company' => $company]);

        return $this->render('cash/bank1c_import_upload.html.twig', [
            'accounts' => $accounts,
        ]);
    }

    #[Route('/preview', name: 'cash_bank1c_import_preview', methods: ['POST'])]
    public function preview(Request $request, MoneyAccountRepository $accountRepository): Response
    {
        if (!$this->isCsrfTokenValid('bank1c_import_upload', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $uploadedFile = $request->files->get('import_file');
        if (!$uploadedFile instanceof UploadedFile) {
            $this->addFlash('danger', 'Пожалуйста, выберите файл для импорта.');

            return $this->redirectToRoute('cash_bank1c_import_upload');
        }

        $accountId = (string) $request->request->get('money_account_id');
        $content = file_get_contents($uploadedFile->getPathname()) ?: '';
        $session = $request->getSession();
        $session->set('bank1c_import', [
            'file_name' => $uploadedFile->getClientOriginalName(),
            'file_content' => $content,
            'account_id' => $accountId,
        ]);

        $company = $this->activeCompanyService->getActiveCompany();
        $accounts = $accountRepository->findBy(['company' => $company]);
        $selectedAccount = null;
        foreach ($accounts as $account) {
            if ($account->getId() === $accountId) {
                $selectedAccount = $account;
                break;
            }
        }

        $previewRows = [
            [
                'date' => '2024-01-01',
                'document' => 'Платёж 001',
                'debit' => '10 000.00',
                'credit' => '0.00',
                'description' => 'Пример операции',
            ],
            [
                'date' => '2024-01-02',
                'document' => 'Платёж 002',
                'debit' => '0.00',
                'credit' => '2 500.00',
                'description' => 'Заглушка для предпросмотра',
            ],
        ];

        return $this->render('cash/bank1c_import_preview.html.twig', [
            'filename' => $uploadedFile->getClientOriginalName(),
            'selectedAccount' => $selectedAccount,
            'previewRows' => $previewRows,
        ]);
    }

    #[Route('/commit', name: 'cash_bank1c_import_commit', methods: ['POST'])]
    public function commit(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('bank1c_import_commit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        return new Response('Not implemented', Response::HTTP_NOT_IMPLEMENTED);
    }
}
