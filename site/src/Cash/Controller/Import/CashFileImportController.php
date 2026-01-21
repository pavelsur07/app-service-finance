<?php

namespace App\Cash\Controller\Import;

use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/cash/import/file')]
class CashFileImportController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
    ) {
    }

    #[Route('', name: 'cash_file_import_upload', methods: ['GET'])]
    public function upload(MoneyAccountRepository $accountRepository): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $accounts = $accountRepository->findBy(['company' => $company]);

        return $this->render('cash/file_import_upload.html.twig', [
            'accounts' => $accounts,
        ]);
    }
}
