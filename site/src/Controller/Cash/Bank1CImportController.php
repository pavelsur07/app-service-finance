<?php

namespace App\Controller\Cash;

use App\Repository\MoneyAccountRepository;
use App\Service\ActiveCompanyService;
use App\Service\Import\ClientBank1CImportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;

#[Route('/cash/import/bank1c')]
class Bank1CImportController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly ClientBank1CImportService $clientBank1CImportService,
    ) {
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

    #[Route('/preview', name: 'cash_bank1c_import_preview', methods: ['GET', 'POST'])]
    public function preview(Request $request, MoneyAccountRepository $accountRepository): Response
    {
        $session = $request->getSession();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('bank1c_import_upload', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $uploadedFile = $request->files->get('import_file');
            if (!$uploadedFile instanceof UploadedFile) {
                $this->addFlash('danger', 'Пожалуйста, выберите файл для импорта.');

                return $this->redirectToRoute('cash_bank1c_import_upload');
            }

            $accountId = (string) $request->request->get('money_account_id');
            if ($accountId === '') {
                $this->addFlash('danger', 'Пожалуйста, выберите счёт.');

                return $this->redirectToRoute('cash_bank1c_import_upload');
            }

            $company = $this->activeCompanyService->getActiveCompany();
            $selectedAccount = $accountRepository->find($accountId);
            if ($selectedAccount === null || $selectedAccount->getCompany() !== $company) {
                $this->addFlash('danger', 'Выбранный счёт недоступен.');

                return $this->redirectToRoute('cash_bank1c_import_upload');
            }

            $rawContent = file_get_contents($uploadedFile->getPathname());
            if ($rawContent === false) {
                $this->addFlash('danger', 'Не удалось прочитать файл импорта.');

                return $this->redirectToRoute('cash_bank1c_import_upload');
            }

            $content = mb_convert_encoding($rawContent, 'UTF-8', 'CP1251');

            $parsedData = $this->clientBank1CImportService->parseHeaderAndDocuments($content);
            $statementAccountValue = $parsedData['header']['РасчСчет'] ?? null;
            $statementPeriodStart = $parsedData['header']['ДатаНачала'] ?? null;
            $statementPeriodEnd = $parsedData['header']['ДатаКонца'] ?? null;
            $statementAccount = is_string($statementAccountValue) ? $statementAccountValue : null;

            $statementAccountNormalized = $this->normalizeAccountNumber($statementAccount);
            $selectedAccountNormalized = $this->normalizeAccountNumber($selectedAccount->getAccountNumber());

            if (
                $statementAccountNormalized === null
                || $selectedAccountNormalized === null
                || $statementAccountNormalized !== $selectedAccountNormalized
            ) {
                $this->addFlash(
                    'danger',
                    sprintf(
                        'Выбран неверный банк или выписка: в файле указан счёт %s',
                        $statementAccount ?? 'не указан',
                    ),
                );

                return $this->redirectToRoute('cash_bank1c_import_upload');
            }

            $documents = is_array($parsedData['documents']) ? $parsedData['documents'] : [];
            $preview = $this->clientBank1CImportService->buildPreview($documents, $statementAccount);

            $session->set('bank1c_import', [
                'file_name' => $uploadedFile->getClientOriginalName(),
                'account_id' => $selectedAccount->getId(),
                'statement_account' => $statementAccount,
                'statement_period_start' => is_string($statementPeriodStart) ? $statementPeriodStart : null,
                'statement_period_end' => is_string($statementPeriodEnd) ? $statementPeriodEnd : null,
                'preview' => $preview,
            ]);
        }

        $state = $session->get('bank1c_import');
        if (!is_array($state) || !isset($state['preview'], $state['account_id'])) {
            $this->addFlash('danger', 'Сессия предпросмотра не найдена. Пожалуйста, загрузите файл заново.');

            return $this->redirectToRoute('cash_bank1c_import_upload');
        }

        $company = $this->activeCompanyService->getActiveCompany();
        $selectedAccount = null;
        if (is_string($state['account_id'])) {
            $selectedAccount = $accountRepository->find($state['account_id']);
        }

        if ($selectedAccount === null || $selectedAccount->getCompany() !== $company) {
            $this->addFlash('danger', 'Выбранный счёт недоступен.');

            return $this->redirectToRoute('cash_bank1c_import_upload');
        }

        $preview = is_array($state['preview']) ? $state['preview'] : [];
        $totalRows = count($preview);
        $perPage = 100;
        $page = max(1, (int) $request->query->get('page', 1));
        $pages = (int) ceil($totalRows / $perPage);
        if ($pages < 1) {
            $pages = 1;
        }

        if ($page > $pages) {
            $page = $pages;
        }

        $offset = ($page - 1) * $perPage;
        $previewRows = array_slice($preview, $offset, $perPage);

        $pager = null;
        if ($totalRows > $perPage) {
            $pager = [
                'current' => $page,
                'pages' => $pages,
                'hasPrevious' => $page > 1,
                'hasNext' => $page < $pages,
                'previous' => max(1, $page - 1),
                'next' => min($pages, $page + 1),
            ];
        }

        return $this->render('cash/bank1c_import_preview.html.twig', [
            'filename' => is_string($state['file_name'] ?? null) ? $state['file_name'] : null,
            'selectedAccount' => $selectedAccount,
            'previewRows' => $previewRows,
            'pager' => $pager,
            'totalRows' => $totalRows,
        ]);
    }

    #[Route('/commit', name: 'cash_bank1c_import_commit', methods: ['POST'])]
    public function commit(Request $request, MoneyAccountRepository $accountRepository): Response
    {
        if (!$this->isCsrfTokenValid('bank1c_import_commit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $session = $request->getSession();
        $state = $session->get('bank1c_import');

        if (!is_array($state) || !isset($state['preview'], $state['account_id'])) {
            $this->addFlash('danger', 'Сессия импорта не найдена. Пожалуйста, загрузите файл заново.');

            return $this->redirectToRoute('cash_bank1c_import_upload');
        }

        $preview = $state['preview'];
        if (!is_array($preview)) {
            $this->addFlash('danger', 'Данные для импорта повреждены. Пожалуйста, повторите загрузку файла.');

            return $this->redirectToRoute('cash_bank1c_import_upload');
        }

        $company = $this->activeCompanyService->getActiveCompany();
        $account = null;
        if (is_string($state['account_id'])) {
            $account = $accountRepository->find($state['account_id']);
        }

        if ($account === null || $account->getCompany() !== $company) {
            $this->addFlash('danger', 'Выбранный счёт недоступен. Пожалуйста, повторите загрузку файла.');

            return $this->redirectToRoute('cash_bank1c_import_upload');
        }

        $overwrite = $request->request->getBoolean('overwrite_duplicates', false);

        $user = $this->getUser();
        $userIdentifier = null;
        if ($user instanceof UserInterface) {
            $userIdentifier = $user->getUserIdentifier();
        } elseif (is_string($user)) {
            $userIdentifier = $user;
        }

        $fileName = is_string($state['file_name'] ?? null) ? $state['file_name'] : null;
        $statementAccount = is_string($state['statement_account'] ?? null) ? $state['statement_account'] : null;
        $periodStart = is_string($state['statement_period_start'] ?? null) ? $state['statement_period_start'] : null;
        $periodEnd = is_string($state['statement_period_end'] ?? null) ? $state['statement_period_end'] : null;

        $summary = $this->clientBank1CImportService->import($preview, $account, $overwrite, [
            'user' => $userIdentifier,
            'file' => $fileName,
            'statement_account' => $statementAccount,
            'date_start' => $periodStart,
            'date_end' => $periodEnd,
        ]);

        $session->remove('bank1c_import');

        return $this->render('cash/bank1c_import_result.html.twig', [
            'filename' => $fileName,
            'selectedAccount' => $account,
            'summary' => $summary,
        ]);
    }

    private function normalizeAccountNumber(?string $accountNumber): ?string
    {
        if ($accountNumber === null) {
            return null;
        }

        $normalized = preg_replace('/[^0-9]/', '', $accountNumber);
        if ($normalized === null || $normalized === '') {
            return null;
        }

        return $normalized;
    }
}
