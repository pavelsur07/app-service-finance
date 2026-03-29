<?php

namespace App\Cash\Controller\Import;

use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Service\Accounts\AccountMasker;
use App\Cash\Service\Import\ClientBank1CImportService;
use App\Cash\Service\Import\ImportLogger;
use App\Company\Entity\User;
use App\Shared\Service\ActiveCompanyService;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cash/import/bank1c')]
class Bank1CImportController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly ClientBank1CImportService $clientBank1CImportService,
        private readonly ImportLogger $importLogger,
        private readonly AccountMasker $accountMasker,
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

    /**
     * GET превью. Читает state из сессии и рендерит таблицу.
     */
    #[Route('/preview', name: 'cash_bank1c_import_preview', methods: ['GET'])]
    public function preview(Request $request, MoneyAccountRepository $accountRepository): Response
    {
        $session = $request->getSession();
        $state = $session->get('bank1c_import');

        if (!is_array($state) || !isset($state['preview'], $state['account_id'])) {
            $this->addFlash('danger', 'Сессия предпросмотра не найдена. Пожалуйста, загрузите файл заново.');

            return $this->redirectToRoute('cash_bank1c_import_upload', [], 303);
        }

        $company = $this->activeCompanyService->getActiveCompany();
        $selectedAccount = null;

        if (is_string($state['account_id'])) {
            $selectedAccount = $accountRepository->find($state['account_id']);
        }

        if (null === $selectedAccount || $selectedAccount->getCompany() !== $company) {
            $this->addFlash('danger', 'Выбранный счёт недоступен.');

            return $this->redirectToRoute('cash_bank1c_import_upload', [], 303);
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

        // Чистый номер счёта для отображения в заголовке превью
        $statementAccountDisplay = is_string($state['statement_account_display'] ?? null)
            ? $state['statement_account_display']
            : (is_string($state['statement_account'] ?? null)
                ? $this->sanitizeAccountForView($state['statement_account'])
                : null);

        return $this->render('cash/bank1c_import_preview.html.twig', [
            'filename' => is_string($state['file_name'] ?? null) ? $state['file_name'] : null,
            'selectedAccount' => $selectedAccount,
            'statementAccountDisplay' => $statementAccountDisplay,
            'previewRows' => $previewRows,
            'pager' => $pager,
            'totalRows' => $totalRows,
        ]);
    }

    /**
     * POST обработчик формы. Готовит превью и кладёт в сессию → PRG на GET превью.
     */
    #[Route('/preview/upload', name: 'cash_bank1c_import_preview_upload', methods: ['POST'])]
    public function previewUpload(Request $request, MoneyAccountRepository $accountRepository): Response
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

        $accountId = (string) $request->request->get('money_account_id');
        if ('' === $accountId) {
            $this->addFlash('danger', 'Пожалуйста, выберите счёт.');

            return $this->redirectToRoute('cash_bank1c_import_upload', [], 303);
        }

        $company = $this->activeCompanyService->getActiveCompany();
        $selectedAccount = $accountRepository->find($accountId);
        if (null === $selectedAccount || $selectedAccount->getCompany() !== $company) {
            $this->addFlash('danger', 'Выбранный счёт недоступен.');

            return $this->redirectToRoute('cash_bank1c_import_upload', [], 303);
        }

        $rawContent = file_get_contents($uploadedFile->getPathname());
        if (false === $rawContent) {
            $this->addFlash('danger', 'Не удалось прочитать файл импорта.');

            return $this->redirectToRoute('cash_bank1c_import_upload', [], 303);
        }

        // 🔧 Нормализуем кодировку и чистим «странные» символы
        $content = $this->normalizeEncoding($rawContent);

        $parsedData = $this->clientBank1CImportService->parseHeaderAndDocuments($content);
        $statementAccountValue = $parsedData['header']['РасчСчет'] ?? null;
        $statementPeriodStart = $parsedData['header']['ДатаНачала'] ?? null;
        $statementPeriodEnd = $parsedData['header']['ДатаКонца'] ?? null;
        $statementAccount = is_string($statementAccountValue) ? $statementAccountValue : null;

        $statementAccountNormalized = $this->normalizeAccountNumber($statementAccount);
        $selectedAccountNormalized = $this->normalizeAccountNumber($selectedAccount->getAccountNumber());

        if (
            null === $statementAccountNormalized
            || null === $selectedAccountNormalized
            || $statementAccountNormalized !== $selectedAccountNormalized
        ) {
            $this->addFlash(
                'danger',
                sprintf(
                    'Выбран неверный банк или выписка: в файле указан счёт %s',
                    $statementAccount ?? 'не указан',
                ),
            );

            return $this->redirectToRoute('cash_bank1c_import_upload', [], 303);
        }

        $documents = is_array($parsedData['documents']) ? $parsedData['documents'] : [];
        $preview = $this->clientBank1CImportService->buildPreview($documents, $statementAccount);

        // 🔧 Чистим поля-счета в строках превью для отображения
        foreach ($preview as &$row) {
            if (is_array($row)) {
                if (isset($row['payerAccount'])) {
                    $row['payerAccount'] = $this->sanitizeAccountForView(
                        is_string($row['payerAccount'] ?? null) ? $row['payerAccount'] : null
                    );
                }
                if (isset($row['receiverAccount'])) {
                    $row['receiverAccount'] = $this->sanitizeAccountForView(
                        is_string($row['receiverAccount'] ?? null) ? $row['receiverAccount'] : null
                    );
                }
            }
        }
        unset($row);

        $statementAccountDisplay = $this->sanitizeAccountForView($statementAccount);

        $session->set('bank1c_import', [
            'file_name' => $uploadedFile->getClientOriginalName(),
            'account_id' => $selectedAccount->getId(),
            'statement_account' => $statementAccount,
            'statement_account_display' => $statementAccountDisplay,
            'statement_period_start' => is_string($statementPeriodStart) ? $statementPeriodStart : null,
            'statement_period_end' => is_string($statementPeriodEnd) ? $statementPeriodEnd : null,
            'preview' => $preview,
        ]);

        // PRG → GET превью
        return $this->redirectToRoute('cash_bank1c_import_preview', [], 303);
    }

    #[Route('/preview/csv', name: 'cash_bank1c_import_preview_csv', methods: ['GET'])]
    public function downloadPreviewCsv(Request $request, MoneyAccountRepository $accountRepository): Response
    {
        $session = $request->getSession();
        $state = $session->get('bank1c_import');

        if (!is_array($state) || !isset($state['preview'], $state['account_id'])) {
            $this->addFlash('danger', 'Сессия предпросмотра не найдена. Пожалуйста, загрузите файл заново.');

            return $this->redirectToRoute('cash_bank1c_import_upload', [], 303);
        }

        $company = $this->activeCompanyService->getActiveCompany();
        $account = null;
        if (is_string($state['account_id'])) {
            $account = $accountRepository->find($state['account_id']);
        }

        if (null === $account || $account->getCompany() !== $company) {
            $this->addFlash('danger', 'Выбранный счёт недоступен.');

            return $this->redirectToRoute('cash_bank1c_import_upload', [], 303);
        }

        $preview = is_array($state['preview']) ? $state['preview'] : [];
        $fileName = is_string($state['file_name'] ?? null) ? $state['file_name'] : 'bank1c';

        $response = new StreamedResponse(function () use ($preview) {
            $handle = fopen('php://output', 'w');
            if (false === $handle) {
                return;
            }

            // UTF-8 BOM
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Дата документа',
                'Тип документа',
                'Номер документа',
                'Тип движения',
                'Сумма',
                'Назначение',
                'Плательщик',
                'ИНН плательщика',
                'Счёт плательщика',
                'Получатель',
                'ИНН получателя',
                'Счёт получателя',
                'Статус контрагента',
            ], ';');

            foreach ($preview as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $direction = (string) ($row['direction'] ?? '');
                $amount = $row['amount'] ?? null;
                $amountValue = null;

                if (null !== $amount && is_numeric($amount)) {
                    $amountNumber = (float) $amount;
                    $sign = 'outflow' === $direction ? '-' : ('inflow' === $direction ? '+' : '');
                    $amountValue = $sign.number_format(abs($amountNumber), 2, '.', '');
                }

                fputcsv($handle, [
                    (string) ($row['docDate'] ?? ''),
                    (string) ($row['docType'] ?? ''),
                    (string) ($row['docNumber'] ?? ''),
                    $direction,
                    $amountValue ?? '',
                    (string) ($row['purpose'] ?? ''),
                    (string) ($row['payerName'] ?? ''),
                    (string) ($row['payerInn'] ?? ''),
                    // Тут можно оставить маску из AccountMasker, но она уже «чистая» после sanitizeAccountForView
                    $this->accountMasker->mask(is_string($row['payerAccount'] ?? null) ? $row['payerAccount'] : null),
                    (string) ($row['receiverName'] ?? ''),
                    (string) ($row['receiverInn'] ?? ''),
                    $this->accountMasker->mask(is_string($row['receiverAccount'] ?? null) ? $row['receiverAccount'] : null),
                    (string) ($row['counterpartyStatus'] ?? ''),
                ], ';');
            }

            fclose($handle);
        });

        $downloadName = $this->generateDownloadName($fileName);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$downloadName.'"');

        return $response;
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

            return $this->redirectToRoute('cash_bank1c_import_upload', [], 303);
        }

        $preview = $state['preview'];
        if (!is_array($preview)) {
            $this->addFlash('danger', 'Данные для импорта повреждены. Пожалуйста, повторите загрузку файла.');

            return $this->redirectToRoute('cash_bank1c_import_upload', [], 303);
        }

        $company = $this->activeCompanyService->getActiveCompany();
        $account = null;
        if (is_string($state['account_id'])) {
            $account = $accountRepository->find($state['account_id']);
        }

        if (null === $account || $account->getCompany() !== $company) {
            $this->addFlash('danger', 'Выбранный счёт недоступен. Пожалуйста, повторите загрузку файла.');

            return $this->redirectToRoute('cash_bank1c_import_upload', [], 303);
        }

        $overwrite = $request->request->getBoolean('overwrite_duplicates', false);

        $user = $this->getUser();
        $userIdentifier = null;
        if ($user instanceof User) {
            $userIdentifier = $user->getId();
        } elseif ($user instanceof UserInterface) {
            $candidate = $user->getUserIdentifier();
            if (is_string($candidate) && Uuid::isValid($candidate)) {
                $userIdentifier = $candidate;
            }
        } elseif (is_string($user) && Uuid::isValid($user)) {
            $userIdentifier = $user;
        }

        $fileName = is_string($state['file_name'] ?? null) ? $state['file_name'] : null;
        $statementAccount = is_string($state['statement_account'] ?? null) ? $state['statement_account'] : null;
        $periodStart = is_string($state['statement_period_start'] ?? null) ? $state['statement_period_start'] : null;
        $periodEnd = is_string($state['statement_period_end'] ?? null) ? $state['statement_period_end'] : null;

        $importLog = $this->importLogger->start($company, 'bank1c', false, $userIdentifier, $fileName);

        try {
            $summary = $this->clientBank1CImportService->import($preview, $account, $overwrite, [
                'user' => $userIdentifier,
                'file' => $fileName,
                'statement_account' => $statementAccount,
                'date_start' => $periodStart,
                'date_end' => $periodEnd,
                'preview' => false,
                'import_log' => $importLog,
            ]);
        } finally {
            $this->importLogger->finish($importLog);
        }

        $session->remove('bank1c_import');

        return $this->render('cash/bank1c_import_result.html.twig', [
            'filename' => $fileName,
            'selectedAccount' => $account,
            'summary' => $summary,
            'importLog' => $importLog,
        ]);
    }

    private function normalizeAccountNumber(?string $accountNumber): ?string
    {
        if (null === $accountNumber) {
            return null;
        }
        $normalized = preg_replace('/[^0-9]/', '', $accountNumber);
        if (null === $normalized || '' === $normalized) {
            return null;
        }

        return $normalized;
    }

    private function generateDownloadName(string $originalFileName): string
    {
        $baseName = pathinfo($originalFileName, \PATHINFO_FILENAME);
        $sanitized = preg_replace('/[^A-Za-z0-9\-_]+/', '_', (string) $baseName);
        if (null === $sanitized || '' === $sanitized) {
            $sanitized = 'bank1c';
        }

        return rtrim($sanitized, '_').'_preview.csv';
    }

    /**
     * Нормализация кодировки входного файла (UTF-8/UTF-16/CP1251) и чистка «странных» символов.
     */
    private function normalizeEncoding(string $rawContent): string
    {
        // Если уже валидный UTF-8 — снимем BOM и почистим
        if (mb_check_encoding($rawContent, 'UTF-8')) {
            return $this->normalizeWeirdSpaces($this->stripUtf8Bom($rawContent));
        }

        // Явные BOM'ы UTF-16
        if (str_starts_with($rawContent, "\xFF\xFE") || str_starts_with($rawContent, "\xFE\xFF")) {
            $enc = str_starts_with($rawContent, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE';
            $converted = @iconv($enc, 'UTF-8//TRANSLIT//IGNORE', $rawContent);
            $content = false !== $converted ? $converted : @mb_convert_encoding($rawContent, 'UTF-8', $enc);

            return $this->normalizeWeirdSpaces($content ?? $rawContent);
        }

        // Детект частых кодировок
        $enc = mb_detect_encoding($rawContent, ['UTF-8', 'UTF-16LE', 'UTF-16BE', 'Windows-1251', 'CP1251'], true);

        if ('UTF-16LE' === $enc || 'UTF-16BE' === $enc) {
            $converted = @iconv($enc, 'UTF-8//TRANSLIT//IGNORE', $rawContent);
            $content = false !== $converted ? $converted : @mb_convert_encoding($rawContent, 'UTF-8', $enc);

            return $this->normalizeWeirdSpaces($content ?? $rawContent);
        }

        // По умолчанию считаем CP1251
        $from = $enc ?: 'CP1251';
        $converted = @iconv($from, 'UTF-8//TRANSLIT//IGNORE', $rawContent);
        $content = false !== $converted ? $converted : @mb_convert_encoding($rawContent, 'UTF-8', $from);

        return $this->normalizeWeirdSpaces($content ?? $rawContent);
    }

    private function stripUtf8Bom(string $s): string
    {
        return str_starts_with($s, "\xEF\xBB\xBF") ? substr($s, 3) : $s;
    }

    /**
     * Чистим NBSP/узкие пробелы/нулевой ширины/мягкие переносы/маркер направления/REPLACEMENT CHAR.
     * Единообразим пробелы вокруг "•" и переносы строк.
     */
    private function normalizeWeirdSpaces(string $s): string
    {
        // Заменяем на обычный пробел
        $s = str_replace([
            "\xC2\xA0",         // NBSP
            "\xE2\x80\xAF",     // NARROW NBSP
            "\xE2\x80\x89",     // THIN SPACE
            "\xE2\x80\x88",     // PUNCTUATION SPACE
            "\xE2\x80\x87",     // FIGURE SPACE
            "\xE2\x80\x8A",     // HAIR SPACE
        ], ' ', $s);

        // Удаляем невидимые и служебные
        $s = str_replace([
            "\xE2\x80\x8B",     // ZWSP
            "\xE2\x80\x8C",     // ZWNJ
            "\xE2\x80\x8D",     // ZWJ
            "\xE2\x80\x8E",     // LRM
            "\xE2\x80\x8F",     // RLM
            "\xC2\xAD",         // SOFT HYPHEN
            "\xEF\xBF\xBD",     // REPLACEMENT CHARACTER (�)
        ], '', $s);

        // Неразрывный дефис → обычный
        $s = str_replace("\xE2\x80\x91", '-', $s);

        // Пробелы вокруг "•" убираем
        $s = preg_replace('/\s*•\s*/u', '•', $s) ?? $s;

        // Единообразные переводы строк
        $s = str_replace(["\r\n", "\r"], "\n", $s);

        // Умеренно схлопываем длинные последовательности пробелов/табов
        $s = preg_replace('/[ \t]{3,}/u', '  ', $s) ?? $s;

        return $s;
    }

    /**
     * Для отображения номеров счетов: убираем мусор, оставляем цифры/пробелы/буллет,
     * схлопываем и делаем компактную маску "4070 28•••• 1479".
     */
    private function sanitizeAccountForView(?string $s): ?string
    {
        if (null === $s) {
            return null;
        }
        $s = $this->normalizeWeirdSpaces($s);

        // Оставляем цифры, пробел и "•"
        $s = preg_replace('/[^0-9• ]+/u', '', $s) ?? $s;

        // Схлопываем множественные пробелы
        $s = preg_replace('/\s{2,}/u', ' ', $s) ?? $s;

        // Группы вида "28 • • • • 1479" → "28•••• 1479"
        $s = preg_replace('/(\d+)\s*(?:•\s*){2,}(\d{2,4})/u', '$1•••• $2', $s) ?? $s;

        return trim($s);
    }
}
