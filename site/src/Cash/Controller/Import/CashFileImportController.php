<?php

declare(strict_types=1);

namespace App\Cash\Controller\Import;

use App\Cash\Application\CreateCashFileImportProfileAction;
use App\Cash\Application\StartCashFileImportJobAction;
use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Entity\Import\CashFileImportProfile;
use App\Cash\Message\Import\CashFileImportMessage;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Import\CashFileImportProfileRepository;
use App\Cash\Service\Import\File\CashFileDescriptionPlan;
use App\Cash\Service\Import\File\FileTabularReader;
use App\Cash\Service\Import\File\HeaderAutoMapper;
use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use App\Shared\Service\Storage\ObjectStorageException;
use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Shared\Service\Storage\TemporaryLocalFile;
use Doctrine\ORM\EntityManagerInterface;
use Pagerfanta\Adapter\ArrayAdapter;
use Pagerfanta\Pagerfanta;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cash/import/file')]
class CashFileImportController extends AbstractController
{
    /**
     * Префикс ключей объектного хранилища для загруженных файлов cash-импорта.
     * Совпадает с прежним подкаталогом var/storage, поэтому под драйвером local
     * файлы физически лежат на тех же местах.
     */
    private const STORAGE_PREFIX = 'cash-file-imports';

    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly CreateCashFileImportProfileAction $createProfile,
        private readonly StartCashFileImportJobAction $startImportJob,
        private readonly MessageBusInterface $messageBus,
        private readonly ObjectStorageInterface $storage,
        private readonly TemporaryLocalFile $temporaryLocalFile,
    ) {
    }

    private function buildStorageKey(string $fileHash, string $storedExtension): string
    {
        $suffix = '' !== $storedExtension ? '.'.$storedExtension : '';

        return sprintf('%s/%s%s', self::STORAGE_PREFIX, $fileHash, $suffix);
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
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
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

        $storageKey = $this->buildStorageKey($fileHash, $normalizedExtension);
        try {
            $this->storage->write($storageKey, $fileContent);
        } catch (\RuntimeException) {
            // Сбой уже записан в error декоратором LoggingObjectStorage.
            // \RuntimeException, а не ObjectStorageException: локальный драйвер
            // при сбое записи бросает \RuntimeException. Ошибки программы
            // (\Error, \LogicException) не глотаются и дают 500.
            $this->addFlash('error', 'Не удалось сохранить файл импорта.');

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
        HeaderAutoMapper $headerAutoMapper,
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

        $storageKey = $this->buildStorageKey($fileHash, is_string($storedExtension) ? $storedExtension : '');

        if (!$this->storage->exists($storageKey)) {
            $this->addFlash('error', 'Файл импорта не найден. Загрузите файл заново.');

            return $this->redirectToRoute('cash_file_import_upload');
        }

        [$headers, $sampleRows] = $this->temporaryLocalFile->with(
            $storageKey,
            static fn (string $filePath): array => [
                $fileTabularReader->readHeader($filePath),
                $fileTabularReader->readSampleRows($filePath),
            ],
        );
        $mapping = [];
        if (isset($importPayload['mapping']) && is_array($importPayload['mapping'])) {
            $mapping = $importPayload['mapping'];
        }
        if ([] === $mapping) {
            $mapping = $headerAutoMapper->suggest($headers);
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
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
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

        $mapping = $this->buildMappingFromRequest($request);
        if (null === $mapping) {
            return $this->redirectToRoute('cash_file_import_mapping');
        }

        $importPayload['mapping'] = $mapping;
        unset($importPayload['description_plan'], $importPayload['description_context'], $importPayload['selected_descriptions'], $importPayload['description_filter'], $importPayload['committed_job_id']);

        $session->set('cash_file_import', $importPayload);

        return new RedirectResponse('/cash/import/file/preview', Response::HTTP_SEE_OTHER);
    }

    #[Route('/mapping/profile/apply', name: 'cash_file_import_mapping_profile_apply', methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
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
        if (!is_string($profileId) || '' === $profileId) {
            $this->addFlash('error', 'Выберите профиль импорта.');

            return $this->redirectToRoute('cash_file_import_mapping');
        }

        $company = $this->activeCompanyService->getActiveCompany();
        $profile = $profileRepository->findOneByIdAndCompanyId($profileId, (string) $company->getId());
        if (null === $profile || CashFileImportProfile::TYPE_CASH_TRANSACTION !== $profile->getType()) {
            $this->addFlash('error', 'Профиль импорта не найден.');

            return $this->redirectToRoute('cash_file_import_mapping');
        }

        $importPayload = $session->get('cash_file_import');
        if (!is_array($importPayload)) {
            $this->addFlash('error', 'Сессия импорта не найдена. Загрузите файл заново.');

            return $this->redirectToRoute('cash_file_import_upload');
        }

        unset($importPayload['description_plan'], $importPayload['description_context'], $importPayload['selected_descriptions'], $importPayload['description_filter'], $importPayload['committed_job_id']);
        $importPayload['mapping'] = $profile->getMapping();
        $importPayload['options'] = $profile->getOptions();
        $importPayload['profile_id'] = $profile->getId();
        $session->set('cash_file_import', $importPayload);

        $this->addFlash('success', 'Профиль применён.');

        return $this->redirectToRoute('cash_file_import_mapping');
    }

    #[Route('/mapping/profile/save', name: 'cash_file_import_mapping_profile_save', methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
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

        $mapping = $this->buildMappingFromRequest($request);
        if (null === $mapping) {
            return $this->redirectToRoute('cash_file_import_mapping');
        }

        $options = [];
        if (isset($importPayload['options']) && is_array($importPayload['options'])) {
            $options = $importPayload['options'];
        }

        $company = $this->activeCompanyService->getActiveCompany();
        $profile = ($this->createProfile)(
            $company,
            $profileName,
            $mapping,
            $options,
            CashFileImportProfile::TYPE_CASH_TRANSACTION
        );

        $importPayload['mapping'] = $mapping;
        unset($importPayload['description_plan'], $importPayload['description_context'], $importPayload['selected_descriptions'], $importPayload['description_filter'], $importPayload['committed_job_id']);
        $importPayload['profile_id'] = $profile->getId();
        $session->set('cash_file_import', $importPayload);

        $this->addFlash('success', 'Профиль сохранён.');

        return $this->redirectToRoute('cash_file_import_mapping');
    }

    #[Route('/preview', name: 'cash_file_import_preview', methods: ['GET', 'POST'])]
    public function preview(
        SessionInterface $session,
        Request $request,
        CashFileDescriptionPlan $plans,
        MoneyAccountRepository $accountRepository,
    ): Response {
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }
        $importPayload = $session->get('cash_file_import');
        if (!is_array($importPayload)) {
            $this->addFlash('error', 'Сессия импорта не найдена. Загрузите файл заново.');

            return $this->redirectToRoute('cash_file_import_upload');
        }

        $committedJobId = $importPayload['committed_job_id'] ?? null;
        if (is_string($committedJobId)) {
            return $this->redirectToRoute('cash_file_import_result', ['id' => $committedJobId]);
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

        $storageKey = $this->buildStorageKey($fileHash, is_string($storedExtension) ? $storedExtension : '');

        if (!$this->storage->exists($storageKey)) {
            $this->addFlash('error', 'Файл импорта не найден. Загрузите файл заново.');

            return $this->redirectToRoute('cash_file_import_upload');
        }

        $actor = $this->getUser() instanceof User ? $this->getUser()->getId() : null;
        $context = hash('sha256', json_encode([$company->getId(), $account->getId(), $fileHash, $mapping, $actor], \JSON_THROW_ON_ERROR));
        try {
            if (($importPayload['description_context'] ?? null) !== $context || !is_string($importPayload['description_plan'] ?? null)) {
                $plan = $this->temporaryLocalFile->with($storageKey, fn (string $filePath): array => $plans->build($filePath, $account, $mapping, $fileHash, $actor));
                $importPayload['description_plan'] = $plans->save($plan);
                $importPayload['description_context'] = $context;
                $importPayload['selected_descriptions'] = [];
            } else {
                $plan = $plans->load((string) $company->getId(), $importPayload['description_plan']);
            }
        } catch (\DomainException $exception) {
            $this->invalidateDescriptionPlan($session, $importPayload);
            if ($exception->getPrevious() instanceof \JsonException) {
                $this->addFlash('error', 'Файл содержит некорректный текст. Проверьте кодировку UTF-8 и загрузите его заново.');

                return $this->redirectToRoute('cash_file_import_upload');
            }
            $this->addFlash('error', 'План импорта устарел или повреждён. Проверьте новый план и повторно выберите обновления.');

            return $this->redirectToRoute('cash_file_import_preview');
        } catch (ObjectStorageException) {
            $this->invalidateDescriptionPlan($session, $importPayload);
            $this->addFlash('error', 'Не удалось прочитать или сохранить план импорта. Загрузите файл заново.');

            return $this->redirectToRoute('cash_file_import_upload');
        }
        $selected = array_fill_keys($importPayload['selected_descriptions'] ?? [], true);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('cash_file_import_selection', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Некорректный CSRF-токен.');
            }
            $available = [];
            foreach ($plan['rows'] as $rowId => $row) {
                if ('update_description' === $row['action']) {
                    $available[(string) $rowId] = true;
                }
            }
            $operation = $request->request->get('selection_action');
            if ('all' === $operation) {
                $selected = $available;
            } elseif ('none' === $operation) {
                $selected = [];
            } else {
                foreach ($request->request->all('visible') as $rowId) {
                    if (!is_string($rowId) || !isset($available[$rowId])) {
                        throw $this->createAccessDeniedException('Недоступная строка плана.');
                    }
                    unset($selected[$rowId]);
                }
                foreach ($request->request->all('selected') as $rowId) {
                    if (!is_string($rowId) || !isset($available[$rowId])) {
                        throw $this->createAccessDeniedException('Недоступная строка плана.');
                    }
                    $selected[$rowId] = true;
                }
            }
        }
        $filter = $request->request->getString('filter', $request->query->getString('filter'));
        $filterChanged = $filter !== ($importPayload['description_filter'] ?? '');
        $importPayload['description_filter'] = $filter;
        $importPayload['selected_descriptions'] = array_map('strval', array_keys($selected));
        $session->set('cash_file_import', $importPayload);
        $summary = array_fill_keys(['create', 'unchanged', 'update_description', 'conflict', 'invalid'], 0);
        $previewRows = [];
        foreach ($plan['rows'] as $rowId => $row) {
            ++$summary[$row['action']];
            if ('' === $filter || $filter === $row['action']) {
                $previewRows[] = $row + ['rowId' => (string) $rowId, 'selected' => isset($selected[$rowId])];
            }
        }
        $page = $filterChanged ? 1 : max(1, $request->request->getInt('page', $request->query->getInt('page', 1)));
        $pager = new Pagerfanta(new ArrayAdapter($previewRows));
        $pager->setMaxPerPage(50);
        $pager->setNormalizeOutOfRangePages(true);
        $pager->setCurrentPage($page);

        return $this->render('cash/file_import_preview.html.twig', [
            'fileName' => $importPayload['file_name'] ?? '',
            'previewRows' => $pager->getCurrentPageResults(),
            'pager' => $pager, 'summary' => $summary, 'filter' => $filter,
            'selectedCount' => count($selected),
        ]);
    }

    #[Route('/commit', name: 'cash_file_import_commit', methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function commit(
        Request $request,
        SessionInterface $session,
        MoneyAccountRepository $accountRepository,
        CashFileDescriptionPlan $plans,
    ): Response {
        if (!$this->isCsrfTokenValid('cash_file_import_commit', $request->request->get('_commit_token', $request->request->get('_token')))) {
            $this->addFlash('error', 'Некорректный CSRF-токен.');

            return $this->redirectToRoute('cash_file_import_preview');
        }

        $importPayload = $session->get('cash_file_import');
        if (!is_array($importPayload)) {
            $this->addFlash('error', 'Сессия импорта не найдена. Загрузите файл заново.');

            return $this->redirectToRoute('cash_file_import_upload');
        }

        $committedJobId = $importPayload['committed_job_id'] ?? null;
        if (is_string($committedJobId)) {
            return $this->redirectToRoute('cash_file_import_result', ['id' => $committedJobId]);
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

        $storedExtension = $importPayload['stored_ext'] ?? null;

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

        $planHash = $importPayload['description_plan'] ?? null;
        if (!is_string($planHash)) {
            $this->addFlash('error', 'Сначала просмотрите план импорта.');

            return $this->redirectToRoute('cash_file_import_preview');
        }
        try {
            $plan = $plans->load((string) $company->getId(), $planHash);
        } catch (\DomainException) {
            $this->invalidateDescriptionPlan($session, $importPayload);
            $this->addFlash('error', 'План импорта устарел или повреждён. Проверьте новый план и повторно выберите обновления.');

            return $this->redirectToRoute('cash_file_import_preview');
        } catch (ObjectStorageException) {
            $this->invalidateDescriptionPlan($session, $importPayload);
            $this->addFlash('error', 'План импорта недоступен. Загрузите файл заново.');

            return $this->redirectToRoute('cash_file_import_upload');
        }
        if ($plan['accountId'] !== $account->getId() || $plan['fileHash'] !== $fileHash
            || $plan['actor'] !== $userIdentifier
            || $plan['mappingHash'] !== hash('sha256', json_encode($mapping, \JSON_THROW_ON_ERROR))) {
            throw $this->createAccessDeniedException('План импорта не соответствует текущему файлу.');
        }
        $selected = $importPayload['selected_descriptions'] ?? [];
        if ($request->request->has('visible') || $request->request->has('selected')) {
            $selection = array_fill_keys($selected, true);
            foreach ($request->request->all('visible') as $rowId) {
                if (!is_string($rowId) || 'update_description' !== ($plan['rows'][$rowId]['action'] ?? null)) {
                    throw $this->createAccessDeniedException('Недоступная строка плана.');
                }
                unset($selection[$rowId]);
            }
            foreach ($request->request->all('selected') as $rowId) {
                if (!is_string($rowId) || 'update_description' !== ($plan['rows'][$rowId]['action'] ?? null)) {
                    throw $this->createAccessDeniedException('Недоступная строка плана.');
                }
                $selection[$rowId] = true;
            }
            $selected = array_map('strval', array_keys($selection));
            $importPayload['selected_descriptions'] = $selected;
            $session->set('cash_file_import', $importPayload);
        }
        foreach ($selected as $rowId) {
            if (!is_string($rowId) || 'update_description' !== ($plan['rows'][$rowId]['action'] ?? null)) {
                throw $this->createAccessDeniedException('Недоступная строка плана.');
            }
        }
        $options = is_string($storedExtension) && '' !== $storedExtension ? ['stored_ext' => $storedExtension] : [];
        $options['description_plan'] = $planHash;
        $options['selected_descriptions'] = $selected;

        try {
            $job = ($this->startImportJob)(
                $company,
                $account,
                'cash:file',
                $fileName,
                $fileHash,
                $mapping,
                $options,
                $userIdentifier,
                $plan['id'],
            );
        } catch (\DomainException) {
            $this->invalidateDescriptionPlan($session, $importPayload);
            $this->addFlash('error', 'Это подтверждение уже использовано или настройки изменились. Проверьте новый план и повторно выберите обновления.');

            return $this->redirectToRoute('cash_file_import_preview');
        }

        if (CashFileImportJob::STATUS_QUEUED === $job->getStatus()) {
            $this->messageBus->dispatch(new CashFileImportMessage($job->getId()));
        }
        $importPayload['committed_job_id'] = $job->getId();
        $session->set('cash_file_import', $importPayload);
        if (CashFileImportJob::STATUS_QUEUED !== $job->getStatus()) {
            return $this->redirectToRoute('cash_file_import_result', ['id' => $job->getId()]);
        }

        return $this->render('cash/file_import_queued.html.twig', [
            'jobId' => $job->getId(),
            'importLogId' => $job->getImportLog()?->getId(),
        ]);
    }

    #[Route('/result/{id}', name: 'cash_file_import_result', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted(ModuleAccess::FINANCE_READ)]
    public function result(string $id, EntityManagerInterface $entityManager, CashFileDescriptionPlan $plans): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $job = $entityManager->getRepository(CashFileImportJob::class)->findOneBy(['id' => $id, 'company' => $company]);
        if (!$job instanceof CashFileImportJob) {
            throw $this->createNotFoundException('Импорт не найден.');
        }

        $options = $job->getOptions();
        $summary = array_fill_keys(['create', 'unchanged', 'update_description', 'conflict', 'invalid'], 0);
        if (is_string($options['description_plan'] ?? null)) {
            try {
                $plan = $plans->load((string) $company->getId(), $options['description_plan']);
                foreach ($plan['rows'] as $row) {
                    ++$summary[$row['action']];
                }
            } catch (ObjectStorageException|\DomainException) {
                $this->addFlash('error', 'Подробный план недоступен. Показаны сохранённые результаты обработки.');
            }
        }

        return $this->render('cash/file_import_result.html.twig', ['job' => $job, 'options' => $options, 'summary' => $summary]);
    }

    /** @param array<string, mixed> $payload */
    private function invalidateDescriptionPlan(SessionInterface $session, array $payload): void
    {
        unset($payload['description_plan'], $payload['description_context'], $payload['selected_descriptions'], $payload['description_filter']);
        $session->set('cash_file_import', $payload);
    }

    /**
     * @return array{date: string, amount: ?string, inflow: ?string, outflow: ?string, counterparty: ?string, description: ?string, currency: ?string, doc_number: ?string}|null
     */
    private function buildMappingFromRequest(Request $request): ?array
    {
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

            return null;
        }

        $hasInflowOutflow = null !== $inflowColumn && null !== $outflowColumn;
        $hasAmount = null !== $amountColumn;

        if (!$hasAmount && !$hasInflowOutflow) {
            $this->addFlash('error', 'Укажите колонку суммы или пары колонок приход/расход.');

            return null;
        }

        // Раздельные колонки приход/расход в приоритете: иначе значение,
        // оставшееся в поле "Сумма" от предыдущего рендера формы (например,
        // после применения другого профиля), молча перекрывает корректно
        // выбранные пользователем inflow/outflow.
        if ($hasInflowOutflow) {
            $amountColumn = null;
        } else {
            $inflowColumn = null;
            $outflowColumn = null;
        }

        return [
            'date' => $dateColumn,
            'amount' => $amountColumn,
            'inflow' => $inflowColumn,
            'outflow' => $outflowColumn,
            'counterparty' => $counterpartyColumn,
            'description' => $descriptionColumn,
            'currency' => $currencyColumn,
            'doc_number' => $docNumberColumn,
        ];
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
