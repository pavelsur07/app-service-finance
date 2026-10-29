<?php

declare(strict_types=1);

namespace App\Finance\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Company\Facade\CompanyFacade;
use App\Company\Security\ModuleAccess;
use App\Finance\Application\Action\ApplyDefaultPLTemplateAction;
use App\Finance\Application\Action\ExtendPLTreeWithMarketplaceAction;
use App\Finance\Application\Action\ImportPLCategoryTreeAction;
use App\Finance\Application\Command\ImportPLCategoryTreeCommand;
use App\Finance\Application\DeletePLCategoryAction;
use App\Finance\Application\DTO\ImportPLCategoryTreeResult;
use App\Finance\Application\DTO\PLCategoryTreeNode;
use App\Finance\Application\SavePLCategoryAction;
use App\Finance\Application\Service\PLCategoryTreeExporter;
use App\Finance\Application\Service\PLCategoryTreeFileReader;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLTemplateApplicationSource;
use App\Finance\Exception\PLCategoryInUseException;
use App\Finance\Form\PLCategoryFormType;
use App\Finance\Repository\PLCategoryRepository;
use App\Finance\Repository\PLTemplateApplicationRepository;
use App\Shared\Service\ActiveCompanyService;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/pl-categories')]
class PLCategoryController extends AbstractController
{
    public function __construct(
        private readonly PLCategoryRepository $categoryRepository,
        private readonly PLCategoryTreeExporter $exporter,
        private readonly PLCategoryTreeFileReader $fileReader,
        private readonly CompanyFacade $companyFacade,
        private readonly ApplyDefaultPLTemplateAction $applyTemplate,
        private readonly PLTemplateApplicationRepository $templateApplications,
        private readonly ExtendPLTreeWithMarketplaceAction $extendWithMarketplace,
    ) {
    }

    #[Route('/', name: 'pl_category_index', methods: ['GET'])]
    public function index(ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();
        $items = $this->categoryRepository->findRootByCompany($company);

        return $this->render('pl_category/index.html.twig', [
            'items' => $items,
        ]);
    }

    #[Route('/export/json', name: 'pl_category_export_json', methods: ['GET'])]
    public function exportJson(ActiveCompanyService $companyService): JsonResponse
    {
        $company = $companyService->getActiveCompany();
        $nodes = $this->exporter->fromEntities($this->categoryRepository->findTreeByCompany($company));
        $exportedAt = new \DateTimeImmutable();

        $response = new JsonResponse($this->exporter->toFilePayload($nodes, (string) $company->getName(), $exportedAt));
        $response->setEncodingOptions(\JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            sprintf('pl-categories-%s-%s.json', $this->fileNameSlug((string) $company->getName()), $exportedAt->format('Y-m-d')),
            // ASCII-фолбэк обязателен: имя компании кириллическое, а старые
            // клиенты читают только параметр filename без UTF-8-варианта.
            sprintf('pl-categories-%s.json', $exportedAt->format('Y-m-d')),
        ));

        return $response;
    }

    #[Route('/import', name: 'pl_category_import', methods: ['GET'])]
    public function import(
        Request $request,
        ActiveCompanyService $companyService,
        ImportPLCategoryTreeAction $importAction,
    ): Response {
        $targetCompany = $companyService->getActiveCompany();
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }

        $sources = array_values(array_filter(
            $this->companyFacade->listAccessibleCompaniesForUser((string) $user->getId()),
            static fn (array $c): bool => $c['id'] !== (string) $targetCompany->getId(),
        ));

        $sourceCompanyId = $request->query->get('sourceCompanyId');
        $preview = null;
        $modes = [];
        $previewMode = null;

        $extendPreview = null;
        if ('marketplace' === $request->query->get('source')) {
            // Статьи маркетплейса в «Базовом» дереве: предпросмотр — что создаст и какие формулы итогов заменит.
            $sourceCompanyId = null;
            try {
                $extendPreview = ($this->extendWithMarketplace)((string) $targetCompany->getId(), true);
            } catch (\DomainException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } elseif ('template' === $request->query->get('source')) {
            // Базовый шаблон только добавляет недостающие статьи: настройки
            // компании (имена, места, типы совпавших по коду статей) не трогаются.
            $sourceCompanyId = null;
            try {
                $preview = ($this->applyTemplate)((string) $targetCompany->getId(), true, PLTemplateApplicationSource::SCREEN, $targetCompany->getBusinessProfile());
                $previewMode = 'template';
            } catch (\DomainException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } elseif (is_string($sourceCompanyId) && '' !== $sourceCompanyId) {
            if (!$this->companyFacade->userHasAccess($sourceCompanyId, (string) $user->getId())) {
                throw new AccessDeniedException();
            }

            try {
                $modes = $this->previewBothModes(
                    $importAction,
                    $this->sourceNodesFromCompany($sourceCompanyId, $targetCompany),
                    (string) $targetCompany->getId(),
                );
                $previewMode = 'company';
            } catch (\DomainException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->render('pl_category/import.html.twig', $modes + [
            'sources' => $sources,
            'selectedSourceCompanyId' => $sourceCompanyId,
            'targetCompanyId' => (string) $targetCompany->getId(),
            'businessProfile' => $targetCompany->getBusinessProfile(),
            'templateOrigin' => $this->templateApplications->findLatestByCompanyId((string) $targetCompany->getId()),
            'extendPreview' => $extendPreview,
            'preview' => $preview,
            'previewMode' => $previewMode,
            'uploadedFileName' => null,
            'uploadedPayload' => null,
        ]);
    }

    #[Route('/import/upload', name: 'pl_category_import_upload', methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function importUpload(
        Request $request,
        ActiveCompanyService $companyService,
        ImportPLCategoryTreeAction $importAction,
    ): Response {
        $targetCompany = $companyService->getActiveCompany();
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('pl-category-import-file'.(string) $targetCompany->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $file = $request->files->get('import_file');
        if (!$file instanceof UploadedFile) {
            $this->addFlash('danger', 'Файл не выбран.');

            return $this->redirectToRoute('pl_category_import');
        }

        if (!$file->isValid()) {
            $this->addFlash('danger', sprintf('Файл не загружен: %s', $file->getErrorMessage()));

            return $this->redirectToRoute('pl_category_import');
        }

        // Размер проверяем до чтения: иначе великоватый файл сначала целиком
        // окажется в памяти и только потом будет отвергнут читателем.
        if ($file->getSize() > PLCategoryTreeFileReader::MAX_BYTES) {
            $this->addFlash('danger', sprintf('Файл слишком большой, максимум — %d КБ.', PLCategoryTreeFileReader::MAX_BYTES / 1024));

            return $this->redirectToRoute('pl_category_import');
        }

        $json = (string) file_get_contents($file->getPathname());

        try {
            $modes = $this->previewBothModes(
                $importAction,
                $this->fileReader->read($json),
                (string) $targetCompany->getId(),
            );
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('pl_category_import');
        }

        $sources = array_values(array_filter(
            $this->companyFacade->listAccessibleCompaniesForUser((string) $user->getId()),
            static fn (array $c): bool => $c['id'] !== (string) $targetCompany->getId(),
        ));

        return $this->render('pl_category/import.html.twig', $modes + [
            'sources' => $sources,
            'selectedSourceCompanyId' => null,
            'targetCompanyId' => (string) $targetCompany->getId(),
            'businessProfile' => $targetCompany->getBusinessProfile(),
            'templateOrigin' => $this->templateApplications->findLatestByCompanyId((string) $targetCompany->getId()),
            'previewMode' => 'file',
            'uploadedFileName' => $file->getClientOriginalName(),
            // Содержимое едет обратно в форме, а не в сессии: применяется то,
            // что несёт эта страница предпросмотра. Общий на всю сессию слот
            // подменял бы файл предпросмотра файлом, загруженным в соседней
            // вкладке. Поле не подписано — и не должно быть: подделать его
            // может только сам аутентифицированный пользователь (CSRF-токен
            // закрывает третью сторону), для своей же активной компании и с
            // полной повторной валидацией содержимого, то есть ровно то, что
            // он и так может сделать, загрузив другой файл.
            'uploadedPayload' => base64_encode($json),
        ]);
    }

    #[Route('/import/apply', name: 'pl_category_import_apply', methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function importApply(
        Request $request,
        ActiveCompanyService $companyService,
        ImportPLCategoryTreeAction $importAction,
    ): Response {
        $targetCompany = $companyService->getActiveCompany();
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('pl-category-import'.(string) $targetCompany->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $sourceCompanyId = null;
        $mode = $request->request->get('mode');

        if ('file' !== $mode && 'template' !== $mode) {
            $sourceCompanyId = $request->request->get('sourceCompanyId');
            if (!is_string($sourceCompanyId) || '' === $sourceCompanyId) {
                throw $this->createNotFoundException();
            }

            if (!$this->companyFacade->userHasAccess($sourceCompanyId, (string) $user->getId())) {
                throw new AccessDeniedException();
            }
        }

        // Существующие статьи перезаписываются, только если пользователь сам отметил это в
        // предпросмотре; применяется ровно тот план, отпечаток которого был на экране.
        $updateExisting = '1' === $request->request->get('updateExisting');

        try {
            $result = 'template' === $mode
                ? ($this->applyTemplate)((string) $targetCompany->getId(), false, PLTemplateApplicationSource::SCREEN, $targetCompany->getBusinessProfile())
                : $importAction(new ImportPLCategoryTreeCommand(
                    null === $sourceCompanyId ? $this->fileReader->read($this->submittedJson($request)) : $this->sourceNodesFromCompany($sourceCompanyId, $targetCompany),
                    (string) $targetCompany->getId(),
                    false,
                    updateExisting: $updateExisting,
                    expectedFingerprint: $this->submittedFingerprint($request, $updateExisting),
                ));
        } catch (\DomainException $e) {
            // Без sourceCompanyId в redirect: GET заново пересчитал бы тот же
            // dry-run и добавил бы второй, дублирующий flash с той же ошибкой.
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('pl_category_import');
        }

        $this->addFlash('success', sprintf(
            'Импорт завершён: создано %d, обновлено %d.',
            \count($result->created),
            \count($result->updated),
        ));

        if ([] !== $result->unresolvedFormulaCodes) {
            $this->addFlash('warning', sprintf(
                'После импорта в формулах категорий этой компании остались ссылки на обозначения, которых в ней нет: %s. Это могут быть как перенесённые формулы, так и формулы категорий, которых в файле не было. Проверьте их вручную.',
                implode(', ', $result->unresolvedFormulaCodes),
            ));
        }

        return $this->redirectToRoute('pl_category_index');
    }

    #[Route('/new', name: 'pl_category_new', methods: ['GET', 'POST'])]
    public function new(Request $request, SavePLCategoryAction $saveCategory, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();

        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }

        $category = new PLCategory(Uuid::uuid4()->toString(), $company);

        $availableCategories = $this->categoryRepository->findTreeByCompany($company);
        $nextSortOrder = $this->categoryRepository->getNextSortOrder($company, $category->getParent());
        if (null === $category->getSortOrder()) {
            $category->setSortOrder($nextSortOrder);
        }

        // A level-5 parent would put the new row at level 6: setParent() rejects
        // that while the form maps data, so it must not be offered at all.
        $parents = array_values(array_filter(
            $availableCategories,
            static fn (PLCategory $candidate): bool => $candidate->getLevel() < 5,
        ));
        $form = $this->createForm(PLCategoryFormType::class, $category, [
            'parents' => $parents,
            'expanded_choices' => true,
        ]);
        $form->remove('isVisible');
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $nextSortOrder = $this->categoryRepository->getNextSortOrder($company, $category->getParent());
            $category->setSortOrder($nextSortOrder);
            $saveCategory($category);

            return $this->redirectToRoute('pl_category_index');
        }

        return $this->render('pl_category/new.html.twig', [
            'form' => $form->createView(),
            'available_categories' => $availableCategories,
            'is_new' => true,
        ]);
    }

    #[Route('/{id}/edit', name: 'pl_category_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, PLCategory $category, SavePLCategoryAction $saveCategory, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();

        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }

        if ($category->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $availableCategories = $this->categoryRepository->findTreeByCompany($company);
        $subtreeHeight = self::subtreeHeight($category, $availableCategories);
        $currentParent = $category->getParent();
        // The current parent always stays offered: otherwise an unrelated edit
        // would submit the empty option and silently move the branch to root.
        $parents = array_values(array_filter(
            $availableCategories,
            static fn (PLCategory $candidate): bool => $candidate === $currentParent
                || ($candidate->getId() !== $category->getId()
                    && !$candidate->isDescendantOf($category)
                    && $candidate->getLevel() + 1 + $subtreeHeight <= 5),
        ));
        $form = $this->createForm(PLCategoryFormType::class, $category, [
            'parents' => $parents,
            'expanded_choices' => true,
        ]);
        $form->remove('isVisible');
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $saveCategory($category);

            return $this->redirectToRoute('pl_category_index');
        }

        return $this->render('pl_category/edit.html.twig', [
            'form' => $form->createView(),
            'item' => $category,
            'available_categories' => $availableCategories,
        ]);
    }

    #[Route('/{id}/delete', name: 'pl_category_delete', methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function delete(Request $request, PLCategory $category, ActiveCompanyService $companyService, DeletePLCategoryAction $deleteAction): Response
    {
        $company = $companyService->getActiveCompany();
        if ($category->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        if ($this->isCsrfTokenValid('delete'.$category->getId(), $request->request->get('_token'))) {
            $companyId = $company->getId();
            if (null !== $companyId) {
                try {
                    $deleteAction($category, $companyId);
                } catch (PLCategoryInUseException) {
                    $this->addFlash('danger', 'Нельзя удалить статью, к которой привязаны операции документов ОПиУ. Сначала перекатегоризируйте их.');

                    return $this->redirectToRoute('pl_category_index');
                }
            }
        }

        return $this->redirectToRoute('pl_category_index');
    }

    /**
     * Предпросмотр импорта из файла или компании в двух режимах: «только добавить» — то, что
     * применится по умолчанию, и «добавить и обновить» — что изменится у существующих статей,
     * если пользователь это отметит. Режимы отказывают независимо: обновление может упереться
     * в предел вложенности, а «только добавить» — в совпавшую статью, которая без обновления
     * не годится в родители новым. Тогда недоступен только отказавший режим, с причиной; отказ
     * обоих — ошибка предпросмотра.
     *
     * @param list<PLCategoryTreeNode> $sourceNodes
     *
     * @return array{preview: ?ImportPLCategoryTreeResult, previewWithUpdates: ?ImportPLCategoryTreeResult, keepError: ?string, updateError: ?string}
     */
    private function previewBothModes(ImportPLCategoryTreeAction $importAction, array $sourceNodes, string $targetCompanyId): array
    {
        $run = static function (bool $updateExisting) use ($importAction, $sourceNodes, $targetCompanyId): ImportPLCategoryTreeResult|\DomainException {
            try {
                return $importAction(new ImportPLCategoryTreeCommand($sourceNodes, $targetCompanyId, true, updateExisting: $updateExisting));
            } catch (\DomainException $e) {
                return $e;
            }
        };

        $keep = $run(false);
        $update = $run(true);
        if ($keep instanceof \DomainException && $update instanceof \DomainException) {
            throw $keep;
        }

        return [
            'preview' => $keep instanceof ImportPLCategoryTreeResult ? $keep : null,
            'previewWithUpdates' => $update instanceof ImportPLCategoryTreeResult ? $update : null,
            'keepError' => $keep instanceof \DomainException ? $keep->getMessage() : null,
            'updateError' => $update instanceof \DomainException ? $update->getMessage() : null,
        ];
    }

    /**
     * Отпечаток плана выбранного режима, показанного в предпросмотре. Без него применять нечего:
     * импорт не знает, что видел пользователь.
     */
    private function submittedFingerprint(Request $request, bool $updateExisting): string
    {
        $fingerprint = $request->request->get($updateExisting ? 'fingerprintWithUpdates' : 'fingerprint');
        if (!is_string($fingerprint) || '' === $fingerprint) {
            throw new \DomainException('Данные предпросмотра не получены — откройте предпросмотр заново.');
        }

        return $fingerprint;
    }

    /**
     * Содержимое файла, приехавшее обратно из формы предпросмотра. Файла в POST
     * уже нет, а серверный слот на сессию означал бы, что вкладки делят одно
     * состояние и применяется не то, что показано на странице.
     *
     * Содержимое проходит те же проверки читателя, что и при загрузке: доверия
     * к полю формы здесь нет, оно лишь переносит уже показанные данные.
     */
    private function submittedJson(Request $request): string
    {
        $payload = $request->request->get('payload');
        if (!is_string($payload) || '' === $payload) {
            throw new \DomainException('Данные предпросмотра не получены — загрузите файл заново.');
        }

        // Длину проверяем до декодирования: иначе прямой POST заставил бы PHP
        // развернуть в память payload сильно больше лимита читателя, и защитой
        // остался бы только post_max_size.
        if (strlen($payload) > 4 * (int) ceil(PLCategoryTreeFileReader::MAX_BYTES / 3) + 4) {
            throw new \DomainException(sprintf('Данные предпросмотра больше допустимого размера (%d КБ).', PLCategoryTreeFileReader::MAX_BYTES / 1024));
        }

        $json = base64_decode($payload, true);
        if (false === $json || '' === $json) {
            throw new \DomainException('Данные предпросмотра не получены — загрузите файл заново.');
        }

        return $json;
    }

    /**
     * Дерево другой компании как источник импорта. Доступ пользователя к
     * компании-источнику проверяется вызывающим методом до этого вызова.
     *
     * @return list<PLCategoryTreeNode>
     */
    private function sourceNodesFromCompany(string $sourceCompanyId, Company $targetCompany): array
    {
        $sourceCompany = $this->companyFacade->findById($sourceCompanyId);
        if (!$sourceCompany instanceof Company) {
            throw new \DomainException('Компания-источник не найдена.');
        }

        if ((string) $sourceCompany->getId() === (string) $targetCompany->getId()) {
            throw new \DomainException('Источник и целевая компания совпадают.');
        }

        return $this->exporter->fromEntities($this->categoryRepository->findTreeByCompany($sourceCompany));
    }

    /**
     * Имя компании для имени файла: кириллица допустима (уходит в UTF-8-вариант
     * Content-Disposition), но разделители пути и кавычки — нет.
     */
    private function fileNameSlug(string $companyName): string
    {
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $companyName) ?? '';
        $slug = trim($slug, '-');

        return '' !== $slug ? mb_substr($slug, 0, 60) : 'company';
    }

    /**
     * Branch height from the already loaded company tree: walking parent links
     * avoids a lazy children query per category.
     *
     * @param PLCategory[] $tree
     */
    private static function subtreeHeight(PLCategory $category, array $tree): int
    {
        $height = 0;
        foreach ($tree as $candidate) {
            $distance = 0;
            for ($node = $candidate; null !== $node && $node !== $category; $node = $node->getParent()) {
                ++$distance;
            }
            if (null !== $node) {
                $height = max($height, $distance);
            }
        }

        return $height;
    }
}
