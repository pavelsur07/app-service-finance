<?php

namespace App\Cash\Controller\Import;

use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Entity\Import\CashFileImportProfile;
use App\Cash\Message\Import\CashFileImportMessage;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Import\CashFileImportProfileRepository;
use App\Cash\Service\Import\ImportLogger;
use App\Cash\Service\Import\File\CashFileRowNormalizer;
use App\Cash\Service\Import\File\FileTabularReader;
use App\Entity\User;
use App\Service\ActiveCompanyService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;

#[Route('/cash/import/file')]
class CashFileImportController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly ImportLogger $importLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
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

    #[Route('/preview/upload', name: 'cash_file_import_upload_post', methods: ['POST'])]
    public function previewUpload(
        Request $request,
        SessionInterface $session,
        MoneyAccountRepository $accountRepository,
    ): Response {
        if (!$this->isCsrfTokenValid('cash_file_import_upload', $request->request->get('_token'))) {
            $this->addFlash('error', 'Некорректный CSRF-токен.');

            return $this->redirectToRoute('cash_file_import_upload');
        }

        $file = $request->files->get('import_file');
        if (!$file instanceof UploadedFile) {
            $this->addFlash('error', 'Загрузите файл для импорта.');

            return $this->redirectToRoute('cash_file_import_upload');
        }

        $accountId = $request->request->get('money_account_id');
        if (!$accountId) {
            $this->addFlash('error', 'Выберите кассу для импорта.');

            return $this->redirectToRoute('cash_file_import_upload');
        }

        $company = $this->activeCompanyService->getActiveCompany();
        $account = $accountRepository->findOneBy([
            'id' => $accountId,
            'company' => $company,
        ]);

        if (!$account) {
            $this->addFlash('error', 'Выбранная касса не найдена.');

            return $this->redirectToRoute('cash_file_import_upload');
        }

        $fileContent = file_get_contents($file->getPathname());
        if (false === $fileContent) {
            $this->addFlash('error', 'Не удалось прочитать файл.');

            return $this->redirectToRoute('cash_file_import_upload');
        }

        $fileHash = hash('sha256', $fileContent);
        $extension = pathinfo($file->getClientOriginalName(), \PATHINFO_EXTENSION);
        $normalizedExtension = '' !== $extension ? strtolower($extension) : '';
        $normalizedExtensionWithDot = '' !== $normalizedExtension ? '.'.$normalizedExtension : '';

        $storageDir = sprintf('%s/var/storage/cash-file-imports', $this->getParameter('kernel.project_dir'));
        if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
            $this->addFlash('error', 'Не удалось подготовить директорию для файлов импорта.');

            return $this->redirectToRoute('cash_file_import_upload');
        }

        $targetPath = sprintf('%s/%s%s', $storageDir, $fileHash, $normalizedExtensionWithDot);
        if (false === file_put_contents($targetPath, $fileContent)) {
            $this->addFlash('error', 'Не удалось сохранить файл на диск.');

            return $this->redirectToRoute('cash_file_import_upload');
        }

        $session->set('cash_file_import', [
            'file_name' => $file->getClientOriginalName(),
            'file_hash' => $fileHash,
            'stored_ext' => $normalizedExtension,
            'account_id' => $accountId,
        ]);

        return new RedirectResponse('/cash/import/file/mapping', Response::HTTP_SEE_OTHER);
    }

    #[Route('/mapping', name: 'cash_file_import_mapping', methods: ['GET'])]
    public function mapping(
        SessionInterface $session,
        FileTabularReader $fileTabularReader,
        CashFileImportProfileRepository $profileRepository,
    ): Response {
        $importPayload = $session->get('cash_file_import');
        if (!is_array($importPayload)) {
            $this->addFlash('error', 'Сессия импорта не найдена. Загрузите файл заново.');

            return $this->redirectToRoute('cash_file_import_upload');
        }

        $fileHash = $importPayload['file_hash'] ?? null;
        $storedExtension = $importPayload['stored_ext'] ?? null;
        if (!$fileHash || !is_string($fileHash)) {
            $this->addFlash('error', 'Не удалось определить файл импорта.');

            return $this->redirectToRoute('cash_file_import_upload');
        }

        $extensionSuffix = '';
        if (is_string($storedExtension) && '' !== $storedExtension) {
            $extensionSuffix = '.'.$storedExtension;
        }

        $filePath = sprintf(
            '%s/var/storage/cash-file-imports/%s%s',
            $this->getParameter('kernel.project_dir'),
            $fileHash,
            $extensionSuffix
        );

        if (!is_file($filePath)) {
            $this->addFlash('error', 'Файл импорта не найден. Загрузите файл заново.');

            return $this->redirectToRoute('cash_file_import_upload');
        }

        $headers = $fileTabularReader->readHeader($filePath);
        $sampleRows = $fileTabularReader->readSampleRows($filePath);
        $mapping = [];
        if (isset($importPayload['mapping']) && is_array($importPayload['mapping'])) {
            $mapping = $importPayload['mapping'];
        }

        $company = $this->activeCompanyService->getActiveCompany();
        $profiles = $profileRepository->findByCompanyAndType($company, CashFileImportProfile::TYPE_CASH_TRANSACTION);

        return $this->render('cash/file_import_mapping.html.twig', [
            'fileName' => $importPayload['file_name'] ?? '',
            'headers' => $headers,
            'sampleRows' => $sampleRows,
            'mapping' => $mapping,
            'profiles' => $profiles,
            'selectedProfileId' => $importPayload['profile_id'] ?? null,
        ]);
    }

    #[Route('/mapping', name: 'cash_file_import_mapping_save', methods: ['POST'])]
    public function mappingSave(Request $request, SessionInterface $session): Response
    {
        if (!$this->isCsrfTokenValid('cash_file_import_mapping', $request->request->get('_token'))) {
            $this->addFlash('error', 'Некорректный CSRF-токен.');

            return $this->redirectToRoute('cash_file_import_mapping');
        }

        $importPayload = $session->get('cash_file_import');
        if (!is_array($importPayload)) {
            $this->addFlash('error', 'Сессия импорта не найдена. Загрузите файл заново.');

            return $this->redirectToRoute('cash_file_import_upload');
        }

        $dateColumn = $this->normalizeMappingColumn($request->request->get('date_column'));
        $amountColumn = $this->normalizeMappingColumn($request->request->get('amount_column'));
        $inflowColumn = $this->normalizeMappingColumn($request->request->get('inflow_column'));
        $outflowColumn = $this->normalizeMappingColumn($request->request->get('outflow_column'));
        $counterpartyColumn = $this->normalizeMappingColumn($request->request->get('counterparty_column'));
        $descriptionColumn = $this->normalizeMappingColumn($request->request->get('description_column'));
        $currencyColumn = $this->normalizeMappingColumn($request->request->get('currency_column'));
        $docNumberColumn = $this->normalizeMappingColumn($request->request->get('doc_number_column'));

        if (null === $dateColumn) {
            $this->addFlash('error', 'Укажите колонку с датой операции.');

            return $this->redirectToRoute('cash_file_import_mapping');
        }

        $hasAmount = null !== $amountColumn;
        $hasInflowOutflow = null !== $inflowColumn && null !== $outflowColumn;

        if (!$hasAmount && !$hasInflowOutflow) {
            $this->addFlash('error', 'Укажите колонку суммы или пары колонок приход/расход.');

            return $this->redirectToRoute('cash_file_import_mapping');
        }

        if ($hasAmount) {
            $inflowColumn = null;
            $outflowColumn = null;
        } else {
            $amountColumn = null;
        }

        $importPayload['mapping'] = [
            'date' => $dateColumn,
            'amount' => $amountColumn,
            'inflow' => $inflowColumn,
            'outflow' => $outflowColumn,
            'counterparty' => $counterpartyColumn,
            'description' => $descriptionColumn,
            'currency' => $currencyColumn,
            'doc_number' => $docNumberColumn,
        ];

        $session->set('cash_file_import', $importPayload);

        return new RedirectResponse('/cash/import/file/preview', Response::HTTP_SEE_OTHER);
    }

    #[Route('/mapping/profile/apply', name: 'cash_file_import_mapping_profile_apply', methods: ['POST'])]
    public function mappingApplyProfile(
        Request $request,
        SessionInterface $session,
        CashFileImportProfileRepository $profileRepository,
    ): Response {
        if (!$this->isCsrfTokenValid('cash_file_import_profile_apply', $request->request->get('_token'))) {
            $this->addFlash('error', 'Некорректный CSRF-токен.');

            return $this->redirectToRoute('cash_file_import_mapping');
        }

        $profileId = $request->request->get('profile_id');
        if (!$profileId) {
            $this->addFlash('error', 'Выберите профиль импорта.');

            return $this->redirectToRoute('cash_file_import_mapping');
        }

        $company = $this->activeCompanyService->getActiveCompany();
        $profile = $profileRepository->find($profileId);
        if (
            !$profile
            || $profile->getCompany()->getId() !== $company->getId()
            || $profile->getType() !== CashFileImportProfile::TYPE_CASH_TRANSACTION
        ) {
            $this->addFlash('error', 'Профиль импорта не найден.');

            return $this->redirectToRoute('cash_file_import_mapping');
        }

        $importPayload = $session->get('cash_file_import');
        if (!is_array($importPayload)) {
            $this->addFlash('error', 'Сессия импорта не найдена. Загрузите файл заново.');

            return $this->redirectToRoute('cash_file_import_upload');
        }

        $importPayload['mapping'] = $profile->getMapping();
        $importPayload['options'] = $profile->getOptions();
        $importPayload['profile_id'] = $profile->getId();
        $session->set('cash_file_import', $importPayload);

        $this->addFlash('success', 'Профиль применён.');

        return $this->redirectToRoute('cash_file_import_mapping');
    }

    #[Route('/mapping/profile/save', name: 'cash_file_import_mapping_profile_save', methods: ['POST'])]
    public function mappingSaveProfile(
        Request $request,
        SessionInterface $session,
    ): Response {
        if (!$this->isCsrfTokenValid('cash_file_import_mapping', $request->request->get('_token'))) {
            $this->addFlash('error', 'Некорректный CSRF-токен.');

            return $this->redirectToRoute('cash_file_import_mapping');
        }

        $importPayload = $session->get('cash_file_import');
        if (!is_array($importPayload)) {
            $this->addFlash('error', 'Сессия импорта не найдена. Загрузите файл заново.');

            return $this->redirectToRoute('cash_file_import_upload');
        }

        $profileName = trim((string) $request->request->get('profile_name'));
        if ('' === $profileName) {
            $this->addFlash('error', 'Укажите название профиля.');

            return $this->redirectToRoute('cash_file_import_mapping');
        }

        $dateColumn = $this->normalizeMappingColumn($request->request->get('date_column'));
        $amountColumn = $this->normalizeMappingColumn($request->request->get('amount_column'));
        $inflowColumn = $this->normalizeMappingColumn($request->request->get('inflow_column'));
        $outflowColumn = $this->normalizeMappingColumn($request->request->get('outflow_column'));
        $counterpartyColumn = $this->normalizeMappingColumn($request->request->get('counterparty_column'));
        $descriptionColumn = $this->normalizeMappingColumn($request->request->get('description_column'));
        $currencyColumn = $this->normalizeMappingColumn($request->request->get('currency_column'));
        $docNumberColumn = $this->normalizeMappingColumn($request->request->get('doc_number_column'));

        if (null === $dateColumn) {
            $this->addFlash('error', 'Укажите колонку с датой операции.');

            return $this->redirectToRoute('cash_file_import_mapping');
        }

        $hasAmount = null !== $amountColumn;
        $hasInflowOutflow = null !== $inflowColumn && null !== $outflowColumn;

        if (!$hasAmount && !$hasInflowOutflow) {
            $this->addFlash('error', 'Укажите колонку суммы или пары колонок приход/расход.');

            return $this->redirectToRoute('cash_file_import_mapping');
        }

        if ($hasAmount) {
            $inflowColumn = null;
            $outflowColumn = null;
        } else {
            $amountColumn = null;
        }

        $mapping = [
            'date' => $dateColumn,
            'amount' => $amountColumn,
            'inflow' => $inflowColumn,
            'outflow' => $outflowColumn,
            'counterparty' => $counterpartyColumn,
            'description' => $descriptionColumn,
            'currency' => $currencyColumn,
            'doc_number' => $docNumberColumn,
        ];

        $options = [];
        if (isset($importPayload['options']) && is_array($importPayload['options'])) {
            $options = $importPayload['options'];
        }

        $company = $this->activeCompanyService->getActiveCompany();
        $profile = new CashFileImportProfile(
            Uuid::uuid4()->toString(),
            $company,
            $profileName,
            $mapping,
            $options,
            CashFileImportProfile::TYPE_CASH_TRANSACTION
        );

        $this->entityManager->persist($profile);
        $this->entityManager->flush();

        $importPayload['mapping'] = $mapping;
        $importPayload['profile_id'] = $profile->getId();
        $session->set('cash_file_import', $importPayload);

        $this->addFlash('success', 'Профиль сохранён.');

        return $this->redirectToRoute('cash_file_import_mapping');
    }

    #[Route('/preview', name: 'cash_file_import_preview', methods: ['GET'])]
    public function preview(
        SessionInterface $session,
        FileTabularReader $fileTabularReader,
        CashFileRowNormalizer $rowNormalizer,
        MoneyAccountRepository $accountRepository,
    ): Response {
        $importPayload = $session->get('cash_file_import');
        if (!is_array($importPayload)) {
            $this->addFlash('error', 'Сессия импорта не найдена. Загрузите файл заново.');

            return $this->redirectToRoute('cash_file_import_upload');
        }

        $mapping = $importPayload['mapping'] ?? null;
        if (!is_array($mapping) || [] === $mapping) {
            $this->addFlash('error', 'Сначала настройте маппинг колонок.');

            return $this->redirectToRoute('cash_file_import_mapping');
        }

        $fileHash = $importPayload['file_hash'] ?? null;
        $storedExtension = $importPayload['stored_ext'] ?? null;
        if (!$fileHash || !is_string($fileHash)) {
            $this->addFlash('error', 'Не удалось определить файл импорта.');

            return $this->redirectToRoute('cash_file_import_upload');
        }

        $accountId = $importPayload['account_id'] ?? null;
        if (!$accountId) {
            $this->addFlash('error', 'Не удалось определить кассу для импорта.');

            return $this->redirectToRoute('cash_file_import_upload');
        }

        $company = $this->activeCompanyService->getActiveCompany();
        $account = $accountRepository->findOneBy([
            'id' => $accountId,
            'company' => $company,
        ]);

        if (!$account) {
            $this->addFlash('error', 'Выбранная касса не найдена.');

            return $this->redirectToRoute('cash_file_import_upload');
        }

        $extensionSuffix = '';
        if (is_string($storedExtension) && '' !== $storedExtension) {
            $extensionSuffix = '.'.$storedExtension;
        }

        $filePath = sprintf(
            '%s/var/storage/cash-file-imports/%s%s',
            $this->getParameter('kernel.project_dir'),
            $fileHash,
            $extensionSuffix
        );

        if (!is_file($filePath)) {
            $this->addFlash('error', 'Файл импорта не найден. Загрузите файл заново.');

            return $this->redirectToRoute('cash_file_import_upload');
        }

        $headers = $fileTabularReader->readHeader($filePath);
        $sampleRows = $fileTabularReader->readSampleRows($filePath, 100);

        $headerLabels = [];
        foreach ($headers as $index => $header) {
            if (null === $header || '' === $header) {
                $headerLabels[$index] = sprintf('Колонка %d', $index + 1);
            } else {
                $headerLabels[$index] = $header;
            }
        }

        $previewRows = [];
        foreach ($sampleRows as $row) {
            $rowByHeader = [];
            foreach ($headerLabels as $index => $label) {
                $rowByHeader[$label] = $row[$index] ?? null;
            }

            $normalized = $rowNormalizer->normalize($rowByHeader, $mapping, $account->getCurrency());
            $previewRows[] = [
                'ok' => $normalized['ok'],
                'errors' => $normalized['errors'],
                'date' => $normalized['occurredAt']?->format('d.m.Y'),
                'direction' => $normalized['direction']?->value,
                'amount' => $normalized['amount'],
                'counterparty' => $normalized['counterpartyName'],
                'description' => $normalized['description'],
                'currency' => $normalized['currency'],
                'docNumber' => $normalized['docNumber'],
            ];
        }

        return $this->render('cash/file_import_preview.html.twig', [
            'fileName' => $importPayload['file_name'] ?? '',
            'previewRows' => $previewRows,
        ]);
    }

    #[Route('/commit', name: 'cash_file_import_commit', methods: ['POST'])]
    public function commit(
        Request $request,
        SessionInterface $session,
        MoneyAccountRepository $accountRepository,
    ): Response {
        if (!$this->isCsrfTokenValid('cash_file_import_commit', $request->request->get('_token'))) {
            $this->addFlash('error', 'Некорректный CSRF-токен.');

            return $this->redirectToRoute('cash_file_import_preview');
        }

        $importPayload = $session->get('cash_file_import');
        if (!is_array($importPayload)) {
            $this->addFlash('error', 'Сессия импорта не найдена. Загрузите файл заново.');

            return $this->redirectToRoute('cash_file_import_upload');
        }

        $mapping = $importPayload['mapping'] ?? null;
        if (!is_array($mapping) || [] === $mapping) {
            $this->addFlash('error', 'Сначала настройте маппинг колонок.');

            return $this->redirectToRoute('cash_file_import_mapping');
        }

        $fileHash = $importPayload['file_hash'] ?? null;
        if (!is_string($fileHash) || '' === $fileHash) {
            $this->addFlash('error', 'Не удалось определить файл импорта.');

            return $this->redirectToRoute('cash_file_import_upload');
        }

        $fileName = $importPayload['file_name'] ?? null;
        if (!is_string($fileName) || '' === $fileName) {
            $this->addFlash('error', 'Не удалось определить имя файла.');

            return $this->redirectToRoute('cash_file_import_upload');
        }

        $accountId = $importPayload['account_id'] ?? null;
        if (!$accountId) {
            $this->addFlash('error', 'Не удалось определить кассу для импорта.');

            return $this->redirectToRoute('cash_file_import_upload');
        }

        $company = $this->activeCompanyService->getActiveCompany();
        $account = $accountRepository->findOneBy([
            'id' => $accountId,
            'company' => $company,
        ]);

        if (!$account) {
            $this->addFlash('error', 'Выбранная касса не найдена.');

            return $this->redirectToRoute('cash_file_import_upload');
        }

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

        $importLog = $this->importLogger->start($company, 'cash:file', false, $userIdentifier, $fileName);
        $this->entityManager->flush();

        $jobId = Uuid::uuid4()->toString();
        $job = new CashFileImportJob(
            $jobId,
            $company,
            $account,
            'cash:file',
            $fileName,
            $fileHash,
            $mapping,
            []
        );
        $job->setImportLog($importLog);

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        $this->messageBus->dispatch(new CashFileImportMessage($jobId));

        $session->remove('cash_file_import');

        return $this->render('cash/file_import_queued.html.twig', [
            'jobId' => $jobId,
            'importLogId' => $importLog->getId(),
        ]);
    }

    private function normalizeMappingColumn(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ('' === $trimmed) {
            return null;
        }

        return $trimmed;
    }
}
