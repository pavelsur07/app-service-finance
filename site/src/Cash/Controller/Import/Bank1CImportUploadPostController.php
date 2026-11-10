<?php

declare(strict_types=1);

namespace App\Cash\Controller\Import;

use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Service\Accounts\AccountMasker;
use App\Cash\Service\Import\ClientBank1CImportService;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cash/import/bank1c')]
final class Bank1CImportUploadPostController extends AbstractController
{
    public function __construct(
        private readonly ClientBank1CImportService $clientBank1CImportService,
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly MoneyAccountRepository $accountRepository,
        private readonly AccountMasker $accountMasker,
        private readonly \App\Cash\Http\Bank1CImportPage $page,
    ) {
    }

    /**
     * POST обработчик формы. Готовит превью и кладёт в сессию → PRG на GET превью.
     */
    #[Route('/preview/upload', name: 'cash_bank1c_import_preview_upload', methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function __invoke(Request $request): Response
    {
        $session = $request->getSession();
        if (!$this->isCsrfTokenValid('bank1c_import_upload', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $uploadedFile = $request->files->get('import_file');
        if (!$uploadedFile instanceof UploadedFile) {
            $this->addFlash('danger', 'Пожалуйста, выберите файл для импорта.');

            return $this->redirectToRoute('cash_bank1c_import_upload', [], 303);
        }
        $rawContent = file_get_contents($uploadedFile->getPathname());
        if (false === $rawContent) {
            $this->addFlash('danger', 'Не удалось прочитать файл импорта.');

            return $this->redirectToRoute('cash_bank1c_import_upload', [], 303);
        }
        // 🔧 Нормализуем кодировку и чистим «странные» символы
        $content = $this->clientBank1CImportService->normalizeEncoding($rawContent);
        $parsedData = $this->clientBank1CImportService->parseHeaderAndDocuments($content);
        $statementAccountValue = $parsedData['header']['РасчСчет'] ?? null;
        $statementPeriodStart = $parsedData['header']['ДатаНачала'] ?? null;
        $statementPeriodEnd = $parsedData['header']['ДатаКонца'] ?? null;
        $statementAccount = is_string($statementAccountValue) ? trim($statementAccountValue) : '';
        if ('' === $statementAccount) {
            $this->addFlash('danger', 'В выписке не указан расчётный счёт.');

            return $this->redirectToRoute('cash_bank1c_import_upload', [], 303);
        }
        $company = $this->activeCompanyService->getActiveCompany();
        $selectedAccount = $this->accountRepository->findOneByCompanyAndAccountNumber($company, $statementAccount);
        if (null === $selectedAccount) {
            $this->addFlash('danger', sprintf('Для выписки не найден активный счёт %s.', $this->accountMasker->mask($statementAccount) ?? $statementAccount));

            return $this->redirectToRoute('cash_bank1c_import_upload', [], 303);
        }
        $documents = is_array($parsedData['documents']) ? $parsedData['documents'] : [];
        $preview = $this->clientBank1CImportService->buildPreview($documents, $statementAccount);
        // 🔧 Чистим поля-счета в строках превью для отображения
        foreach ($preview as &$row) {
            if (is_array($row)) {
                if (isset($row['payerAccount'])) {
                    $row['payerAccount'] = $this->page->sanitizeAccountForView(is_string($row['payerAccount']) ? $row['payerAccount'] : null);
                }
                if (isset($row['receiverAccount'])) {
                    $row['receiverAccount'] = $this->page->sanitizeAccountForView(is_string($row['receiverAccount']) ? $row['receiverAccount'] : null);
                }
            }
        }
        unset($row);
        $statementAccountDisplay = $this->page->sanitizeAccountForView($statementAccount);
        $session->set('bank1c_import', ['company_id' => (string) $company->getId(), 'file_name' => $uploadedFile->getClientOriginalName(), 'account_id' => $selectedAccount->getId(), 'statement_account' => $statementAccount, 'statement_account_display' => $statementAccountDisplay, 'statement_period_start' => is_string($statementPeriodStart) ? $statementPeriodStart : null, 'statement_period_end' => is_string($statementPeriodEnd) ? $statementPeriodEnd : null, 'preview' => $preview]);

        // PRG → GET превью
        return $this->redirectToRoute('cash_bank1c_import_preview', [], 303);
    }
}
