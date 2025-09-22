<?php

namespace App\Controller\Finance;

use App\Repository\MoneyAccountRepository;
use App\Service\ActiveCompanyService;
use App\Service\Bank1C\Bank1CImportService;
use App\Service\Bank1C\Bank1CStatementParser;
use App\Service\Bank1C\Dto\Bank1CDocument;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/finance/imports/bank-1c')]
class Bank1CImportController extends AbstractController
{
    private const SESSION_KEY = 'bank1c_import_previews';

    public function __construct(
        private ActiveCompanyService $companyService,
        private LoggerInterface $logger,
        private Bank1CStatementParser $statementParser,
    ) {
    }

    #[Route('', name: 'bank1c_import_form', methods: ['GET'])]
    public function form(Request $request): Response
    {
        $session = $request->getSession();
        if ($session instanceof SessionInterface) {
            $token = $request->query->get('clear');
            if (is_string($token) && '' !== $token) {
                $this->removePreview($session, $token);
            }
            $this->purgeExpiredPreviews($session);
        }

        return $this->render('finance/import/bank1c/upload.html.twig');
    }

    #[Route('', name: 'bank1c_import_handle', methods: ['POST'])]
    public function handle(
        Request $request,
        MoneyAccountRepository $accountRepo,
    ): Response {
        if (!$this->isCsrfTokenValid('bank1c_import', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        /** @var UploadedFile|null $file */
        $file = $request->files->get('statement');
        if (!$file) {
            $this->addFlash('danger', 'Файл не загружен');

            return $this->redirectToRoute('bank1c_import_form');
        }
        $extension = strtolower($file->getClientOriginalExtension() ?? '');
        $size = $file->getSize() ?? 0;
        $allowedExtensions = ['', 'txt'];
        $isExtensionAllowed = in_array($extension, $allowedExtensions, true);
        $head = @file_get_contents($file->getPathname(), false, null, 0, 2048);
        $hasSignature = is_string($head) && str_contains($head, '1CClientBankExchange');
        if ($size > 10 * 1024 * 1024 || !$isExtensionAllowed || !$hasSignature) {
            $this->addFlash('danger', 'Недопустимый файл');

            return $this->redirectToRoute('bank1c_import_form');
        }
        $raw = file_get_contents($file->getPathname());
        if (false === $raw) {
            $this->addFlash('danger', 'Не удалось прочитать файл');

            return $this->redirectToRoute('bank1c_import_form');
        }

        try {
            $statement = $this->statementParser->parse($raw);
        } catch (\Throwable $e) {
            $this->logger->error('Не удалось разобрать файл 1C', ['exception' => $e]);
            $this->addFlash('danger', 'Не удалось разобрать файл. Проверьте формат.');

            return $this->redirectToRoute('bank1c_import_form');
        }

        $accountNumberRaw = $statement->account['НомерСчета']
            ?? ($statement->account['РасчСчет'] ?? ($statement->header['РасчСчет'] ?? null));
        $normalizedAccount = $this->normalizeAccount($accountNumberRaw);
        $bankName = $this->extractBankName($statement->header, $statement->account);

        $company = $this->companyService->getActiveCompany();
        $account = $normalizedAccount !== ''
            ? $accountRepo->findOneByNormalizedAccountNumber($company, $normalizedAccount)
            : null;

        $operations = $this->buildPreviewOperations($statement->documents, $normalizedAccount);
        $session = $request->getSession();
        if (!$session instanceof SessionInterface) {
            throw $this->createAccessDeniedException('Сессия недоступна');
        }
        $token = $this->storePreview($session, [
            'raw' => $raw,
            'filename' => $file->getClientOriginalName(),
            'accountNumberRaw' => $accountNumberRaw,
            'normalizedAccount' => $normalizedAccount,
            'bankName' => $bankName,
        ]);

        $this->logger->info('Создано превью импорта 1C', [
            'token' => $token,
            'account_number' => $accountNumberRaw,
            'account_found' => (bool) $account,
            'bank' => $bankName,
        ]);

        return $this->render('finance/import/bank1c/preview.html.twig', [
            'account' => $account,
            'accountNumber' => $accountNumberRaw,
            'bankName' => $bankName,
            'operations' => $operations,
            'token' => $token,
            'canProceed' => null !== $account && '' !== $normalizedAccount,
        ]);
    }

    /**
     * @param Bank1CDocument[] $documents
     * @return array<int, array<string, string|null>>
     */
    private function buildPreviewOperations(array $documents, ?string $normalizedAccount): array
    {
        $operations = [];
        foreach (array_slice($documents, 0, 10) as $document) {
            $operations[] = [
                'date' => $this->resolveDocumentDate($document, $normalizedAccount),
                'amount' => $document->amount,
                'purpose' => $document->purpose,
                'counterparty' => $this->resolveDocumentCounterparty($document, $normalizedAccount),
                'number' => $document->number,
                'type' => $document->type,
            ];
        }

        return $operations;
    }

    private function resolveDocumentDate(Bank1CDocument $document, ?string $normalizedAccount): ?string
    {
        if ($normalizedAccount) {
            $payer = $this->normalizeAccount($document->payerAccount);
            $payee = $this->normalizeAccount($document->payeeAccount);
            if ($payer === $normalizedAccount) {
                return $document->dateDebited ?: ($document->date ?: $document->dateCredited);
            }
            if ($payee === $normalizedAccount) {
                return $document->dateCredited ?: ($document->date ?: $document->dateDebited);
            }
        }

        return $document->date ?? $document->dateCredited ?? $document->dateDebited;
    }

    private function resolveDocumentCounterparty(Bank1CDocument $document, ?string $normalizedAccount): ?string
    {
        if ($normalizedAccount) {
            $payer = $this->normalizeAccount($document->payerAccount);
            $payee = $this->normalizeAccount($document->payeeAccount);
            if ($payer === $normalizedAccount && $document->payeeName) {
                return $document->payeeName;
            }
            if ($payee === $normalizedAccount && $document->payerName) {
                return $document->payerName;
            }
        }

        return $document->payerName
            ?: ($document->payeeName
                ?: ($document->payerInn ?: ($document->payeeInn ?: null)));
    }

    private function normalizeAccount(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) ($value ?? '')) ?? '';
    }

    private function extractBankName(array $header, array $account): ?string
    {
        foreach (['Банк', 'НаименованиеБанка', 'Банк1', 'БИК'] as $key) {
            if (!empty($account[$key])) {
                return $account[$key];
            }
            if (!empty($header[$key])) {
                return $header[$key];
            }
        }

        return null;
    }

    private function storePreview(SessionInterface $session, array $data): string
    {
        $this->purgeExpiredPreviews($session);
        $token = bin2hex(random_bytes(16));
        $payload = $session->get(self::SESSION_KEY, []);
        $payload[$token] = $data + ['createdAt' => time()];
        $session->set(self::SESSION_KEY, $payload);

        return $token;
    }

    private function removePreview(SessionInterface $session, string $token): void
    {
        $payload = $session->get(self::SESSION_KEY, []);
        if (isset($payload[$token])) {
            unset($payload[$token]);
            $session->set(self::SESSION_KEY, $payload);
        }
    }

    private function purgeExpiredPreviews(SessionInterface $session, int $ttl = 3600): void
    {
        $payload = $session->get(self::SESSION_KEY, []);
        $changed = false;
        $threshold = time() - $ttl;
        foreach ($payload as $token => $item) {
            if (!is_array($item) || !isset($item['createdAt']) || $item['createdAt'] < $threshold) {
                unset($payload[$token]);
                $changed = true;
            }
        }
        if ($changed) {
            $session->set(self::SESSION_KEY, $payload);
        }
    }

    #[Route('/confirm', name: 'bank1c_import_confirm', methods: ['POST'])]
    public function confirm(
        Request $request,
        MoneyAccountRepository $accountRepo,
        Bank1CImportService $importService,
    ): Response {
        $token = $request->request->get('token');
        if (!is_string($token) || '' === $token) {
            $this->addFlash('danger', 'Не найден идентификатор операции импорта. Повторите загрузку файла.');

            return $this->redirectToRoute('bank1c_import_form');
        }

        if (!$this->isCsrfTokenValid('bank1c_import_confirm_'.$token, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $session = $request->getSession();
        if (!$session instanceof SessionInterface) {
            throw $this->createAccessDeniedException('Сессия недоступна');
        }
        $payload = $session->get(self::SESSION_KEY, []);
        $preview = $payload[$token] ?? null;
        if (!is_array($preview) || !isset($preview['raw'])) {
            $this->addFlash('danger', 'Данные превью не найдены. Повторите импорт.');

            return $this->redirectToRoute('bank1c_import_form');
        }

        $raw = (string) $preview['raw'];

        try {
            $statement = $this->statementParser->parse($raw);
        } catch (\Throwable $e) {
            $this->removePreview($session, $token);
            $this->logger->error('Не удалось повторно разобрать файл 1C при подтверждении', ['exception' => $e]);
            $this->addFlash('danger', 'Не удалось обработать файл. Повторите импорт.');

            return $this->redirectToRoute('bank1c_import_form');
        }

        $accountNumberRaw = $statement->account['НомерСчета']
            ?? ($statement->account['РасчСчет'] ?? ($statement->header['РасчСчет'] ?? null));
        $normalizedAccount = $this->normalizeAccount($accountNumberRaw);
        $bankName = $this->extractBankName($statement->header, $statement->account);

        $company = $this->companyService->getActiveCompany();
        $account = $normalizedAccount !== ''
            ? $accountRepo->findOneByNormalizedAccountNumber($company, $normalizedAccount)
            : null;

        if (!$account) {
            $this->removePreview($session, $token);
            $this->logger->warning('Подтверждение импорта 1C без найденного счёта', [
                'token' => $token,
                'account_number' => $accountNumberRaw,
                'bank' => $bankName,
            ]);
            $this->addFlash('danger', sprintf(
                'Счёт %s (%s) не найден в системе. Создайте счёт и повторите импорт.',
                $accountNumberRaw ?: '—',
                $bankName ?: 'банк не указан'
            ));

            return $this->redirectToRoute('bank1c_import_form');
        }

        $this->logger->info('Подтверждён импорт 1C', [
            'token' => $token,
            'account_id' => $account->getId(),
            'account_number' => $accountNumberRaw,
            'bank' => $bankName,
        ]);

        $result = $importService->import($company, $account, $raw, $preview['filename'] ?? null);

        $this->removePreview($session, $token);

        $this->logger->info('Импорт 1C завершён', [
            'token' => $token,
            'created' => $result->created,
            'duplicates' => $result->duplicates,
            'errors' => count($result->errors),
            'bank' => $bankName,
        ]);

        return $this->render('finance/import/bank1c/result.html.twig', [
            'result' => $result,
        ]);
    }
}
