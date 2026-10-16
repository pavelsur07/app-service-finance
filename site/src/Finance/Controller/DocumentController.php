<?php

declare(strict_types=1);

namespace App\Finance\Controller;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Company\Entity\Company;
use App\Company\Facade\CompanyFacade;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\Company\Repository\ProjectDirectionRepository;
use App\Company\Security\ModuleAccess;
use App\Finance\Application\RestoreDocumentAction;
use App\Finance\Application\Service\DocumentPlContribution;
use App\Finance\Application\Service\FinanceDocumentResponsibilityCenterNormalizer;
use App\Finance\Application\Service\PlNatureResolver;
use App\Finance\Application\Service\PLRegisterUpdater;
use App\Finance\Application\SoftDeleteDocumentAction;
use App\Finance\DTO\DocumentListDTO;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Enum\DocumentStatus;
use App\Finance\Enum\PlNature;
use App\Finance\Form\DocumentType;
use App\Finance\Repository\DocumentRepository;
use App\Finance\Repository\PLCategoryRepository;
use App\Shared\Audit\AuditContextProvider;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/documents')]
class DocumentController extends AbstractController
{
    public function __construct(
        private readonly PLRegisterUpdater $plRegisterUpdater,
        private readonly FinanceDocumentResponsibilityCenterNormalizer $responsibilityCenterNormalizer,
    ) {
    }

    #[Route('/', name: 'document_index', methods: ['GET'])]
    public function index(
        Request $request,
        DocumentRepository $repo,
        ActiveCompanyService $companyService,
        PlNatureResolver $natureResolver,
        CompanyFacade $companyFacade,
        FinancialResponsibilityCenterFacade $responsibilityCenterFacade,
        DocumentPlContribution $plContribution,
    ): Response {
        $company = $companyService->getActiveCompany();
        $companyId = (string) $company->getId();
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = (int) $request->query->get('limit', 20);

        $filters = new DocumentListDTO(
            $company,
            $page,
            $limit,
            $request->query->getString('dateFrom'),
            $request->query->getString('dateTo'),
            $request->query->getString('type'),
            $request->query->getString('status'),
            $request->query->getString('number'),
            $request->query->getString('counterparty'),
            $request->query->getString('q'),
            $request->query->getString('projectDirection'),
            $request->query->getString('responsibilityCenter'),
            $request->query->getString('direction'),
        );
        $pager = $repo->findByCompany($filters);
        $items = iterator_to_array($pager->getCurrentPageResults());

        $documentNatures = [];
        $contributions = [];
        $responsibilityCenterIds = [];
        foreach ($items as $item) {
            $id = $item->getId() ?? spl_object_hash($item);
            $documentNatures[$id] = $this->buildNatureView($natureResolver->forDocument($item));
            $contributions[$id] = $plContribution->forDocument($item);
            $responsibilityCenterIds[] = $item->getResponsibilityCenterId();
            foreach ($item->getOperations() as $operation) {
                $responsibilityCenterIds[] = $operation->getResponsibilityCenterId();
            }
        }

        return $this->render('document/index.html.twig', [
            'items' => $items,
            'documentNatures' => $documentNatures,
            'contributions' => $contributions,
            'pager' => $pager,
            'limit' => $pager->getMaxPerPage(),
            'filters' => $filters,
            'totals' => $repo->summarizeByCompany($companyId, $filters),
            'responsibilityCenterNames' => $responsibilityCenterFacade->getNamesByIds(
                $companyId,
                array_values(array_filter($responsibilityCenterIds, static fn (?string $id): bool => null !== $id)),
            ),
            'projectDirections' => $companyFacade->listProjectDirectionEntities($companyId, true),
            'responsibilityCenters' => $responsibilityCenterFacade->getActiveChoices($companyId),
        ]);
    }

    #[Route('/deleted', name: 'document_deleted_index', methods: ['GET'])]
    public function deletedIndex(Request $request, DocumentRepository $repo, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();
        $page = max(1, (int) $request->query->get('page', 1));
        $requestedLimit = (int) $request->query->get('limit', 20);
        $limit = in_array($requestedLimit, [20, 30, 50], true) ? $requestedLimit : 20;

        $pager = $repo->paginateDeletedByCompany((string) $company->getId(), $page, $limit);

        return $this->render('document/deleted_index.html.twig', [
            'items' => iterator_to_array($pager->getCurrentPageResults()),
            'pager' => $pager,
            'limit' => $pager->getMaxPerPage(),
        ]);
    }

    #[Route('/new', name: 'document_new', methods: ['GET', 'POST'])]
    public function new(Request $request, DocumentRepository $repo, PLCategoryRepository $catRepo, ProjectDirectionRepository $projectDirectionRepo, EntityManagerInterface $em, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();

        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }

        $document = new Document(Uuid::uuid4()->toString(), $company);
        $document->setStatus(DocumentStatus::ACTIVE);

        $categories = $catRepo->findTreeByCompany($company);
        $projectDirections = $projectDirectionRepo->findByCompany($company);
        $form = $this->createForm(DocumentType::class, $document, [
            'categories' => $categories,
            'project_directions' => $projectDirections,
            'company' => $company,
        ]);
        $form->handleRequest($request);
        $initialStatus = $document->getStatus();

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->responsibilityCenterNormalizer->prepareNewManualDocument($document, $company);
            } catch (\DomainException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }

            if ($form->isValid()) {
                $em->persist($document);
                $em->flush();

                $this->plRegisterUpdater->updateForDocument($document);

                return $this->redirectToRoute('document_index');
            }
        }

        return $this->render('document/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/copy', name: 'document_copy', methods: ['GET', 'POST'])]
    public function copy(
        Request $request,
        Document $document,
        PLCategoryRepository $catRepo,
        ProjectDirectionRepository $projectDirectionRepo,
        EntityManagerInterface $em,
        ActiveCompanyService $companyService,
    ): Response {
        $company = $companyService->getActiveCompany();

        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }

        if ($document->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }
        $this->denyDeletedDocument($document);

        $copy = $this->duplicateDocument($document, $company);

        $categories = $catRepo->findTreeByCompany($company);
        $projectDirections = $projectDirectionRepo->findByCompany($company);
        $form = $this->createForm(DocumentType::class, $copy, [
            'categories' => $categories,
            'project_directions' => $projectDirections,
            'company' => $company,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->responsibilityCenterNormalizer->prepareNewManualDocument($copy, $company);
            } catch (\DomainException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }

            if ($form->isValid()) {
                $em->persist($copy);
                $em->flush();

                $this->plRegisterUpdater->updateForDocument($copy);

                return $this->redirectToRoute('document_index');
            }
        }

        return $this->render('document/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}', name: 'document_show', methods: ['GET'])]
    public function show(Document $document, ActiveCompanyService $companyService, PlNatureResolver $natureResolver): Response
    {
        $company = $companyService->getActiveCompany();
        if ($document->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }
        $this->denyDeletedDocument($document);

        $documentNature = $this->buildNatureView($natureResolver->forDocument($document));
        $operationViews = [];
        foreach ($document->getOperations() as $operation) {
            $nature = $natureResolver->forOperation($operation);
            $category = $operation->getPlCategory();
            $natureValue = $nature?->value;
            $operationViews[] = [
                'operation' => $operation,
                'categoryName' => $category?->getName(),
                'nature' => $natureValue,
                'natureLabel' => $natureValue === PlNature::INCOME->value ? 'Доход' : ($natureValue === PlNature::EXPENSE->value ? 'Расход' : null),
                'badgeClass' => $natureValue === PlNature::INCOME->value ? 'bg-green-lt text-green' : ($natureValue === PlNature::EXPENSE->value ? 'bg-red-lt text-red' : ''),
                'isFallback' => null === $category && null !== $nature,
                'needsCategorization' => null === $category && null === $nature,
            ];
        }

        return $this->render('document/show.html.twig', [
            'item' => $document,
            'documentNature' => $documentNature,
            'operationViews' => $operationViews,
        ]);
    }

    #[Route('/{id}/edit', name: 'document_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Document $document, PLCategoryRepository $catRepo, ProjectDirectionRepository $projectDirectionRepo, EntityManagerInterface $em, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();

        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }

        if ($document->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }
        $this->denyDeletedDocument($document);

        $categories = $catRepo->findTreeByCompany($company);
        $projectDirections = $projectDirectionRepo->findByCompany($company);
        $initialResponsibilityCenterSnapshot = $this->responsibilityCenterNormalizer->snapshotDocument($document);
        $initialOperationResponsibilityCenterSnapshots = $this->responsibilityCenterNormalizer->snapshotOperations($document);
        $form = $this->createForm(DocumentType::class, $document, [
            'categories' => $categories,
            'project_directions' => $projectDirections,
            'company' => $company,
        ]);
        $initialStatus = $document->getStatus();
        $initialDate = $document->getDate()->setTime(0, 0);
        // Сверка — по сохранённому состоянию, до применения формы: после handleRequest в документе
        // могут быть отклонённые валидацией операции без суммы, и пересчёт по ним падал.
        $transactionAllocation = $this->buildTransactionAllocationView($document);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $cashTransaction = $document->getCashTransaction();

            // Сумму документа считают только по валидным операциям: у операции, отклонённой
            // валидацией (пустая сумма), сумма не задана, и подсчёт падал с 500.
            if ($form->isValid() && $cashTransaction instanceof CashTransaction) {
                try {
                    $cashTransaction->assertCanAllocateAmount($document->getTotalAmount(), $document);
                } catch (\DomainException $e) {
                    $form->addError(new FormError($e->getMessage()));
                }
            }

            if ($form->isValid()) {
                try {
                    $this->responsibilityCenterNormalizer->prepareExistingManualDocument(
                        $document,
                        $company,
                        $initialResponsibilityCenterSnapshot,
                        $initialOperationResponsibilityCenterSnapshots,
                    );
                } catch (\DomainException $exception) {
                    $form->addError(new FormError($exception->getMessage()));
                }

                if ($form->isValid()) {
                    if ($cashTransaction instanceof CashTransaction) {
                        $cashTransaction->recalculateAllocatedAmount();
                    }

                    $em->flush();

                    $daysToRecalc = [];
                    $currentDate = $document->getDate()->setTime(0, 0);

                    if (DocumentStatus::ACTIVE === $initialStatus) {
                        $daysToRecalc[$initialDate->format('Y-m-d')] = $initialDate;
                    }

                    if (DocumentStatus::ACTIVE === $document->getStatus()) {
                        $daysToRecalc[$currentDate->format('Y-m-d')] = $currentDate;
                    }

                    foreach ($daysToRecalc as $day) {
                        $this->plRegisterUpdater->recalcRange($company, $day, $day);
                    }

                    return $this->redirectToRoute('document_index');
                }
            }
        }

        return $this->render('document/edit.html.twig', [
            'form' => $form->createView(),
            'item' => $document,
            'transactionAllocation' => $transactionAllocation,
        ]);
    }

    #[Route('/{id}/json', name: 'document_export_json', methods: ['GET'])]
    public function exportJson(Document $document, ActiveCompanyService $companyService): JsonResponse
    {
        $company = $companyService->getActiveCompany();
        if ($document->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }
        $this->denyDeletedDocument($document);

        $payload = [
            'id' => $document->getId(),
            'date' => $document->getDate()->format('Y-m-d'),
            'number' => $document->getNumber(),
            'description' => $document->getDescription(),
            'type' => $document->getType()->value,
            'status' => $document->getStatus()->value,
            'source' => $document->getSource()?->value,
            'stream' => $document->getStream()?->value,
            'counterparty' => [
                'id' => $document->getCounterparty()?->getId(),
                'name' => $document->getCounterparty()?->getName(),
            ],
            'projectDirection' => [
                'id' => $document->getProjectDirection()?->getId(),
                'name' => $document->getProjectDirection()?->getName(),
            ],
            'operations' => [],
        ];

        foreach ($document->getOperations() as $operation) {
            $payload['operations'][] = [
                'id' => $operation->getId(),
                'amount' => $operation->getAmount(),
                'comment' => $operation->getComment(),
                'category' => [
                    'id' => $operation->getCategory()?->getId(),
                    'name' => $operation->getCategory()?->getName(),
                ],
                'counterparty' => [
                    'id' => $operation->getCounterparty()?->getId(),
                    'name' => $operation->getCounterparty()?->getName(),
                ],
                'projectDirection' => [
                    'id' => $operation->getProjectDirection()?->getId(),
                    'name' => $operation->getProjectDirection()?->getName(),
                ],
            ];
        }

        $response = $this->json($payload, Response::HTTP_OK, [], [
            'json_encode_options' => \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE,
        ]);
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="document-%s.json"', $document->getId()));

        return $response;
    }

    #[Route('/{id}/delete', name: 'document_delete', methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function delete(
        Request $request,
        Document $document,
        ActiveCompanyService $companyService,
        SoftDeleteDocumentAction $softDeleteDocument,
        AuditContextProvider $auditContextProvider,
    ): Response {
        $company = $companyService->getActiveCompany();
        if ($document->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        if ($this->isCsrfTokenValid('delete'.$document->getId(), $request->request->get('_token'))) {
            $softDeleteDocument(
                (string) $company->getId(),
                (string) $document->getId(),
                $auditContextProvider->getActorUserId(),
                'manual-ui',
            );
            $this->addFlash('success', 'Документ ОПиУ перемещён в «Удалённые».');
        } else {
            $this->addFlash('danger', 'Неверный CSRF токен.');
        }

        return $this->redirectToRoute('document_index');
    }

    #[Route('/{id}/restore', name: 'document_restore', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function restore(
        string $id,
        Request $request,
        DocumentRepository $repo,
        ActiveCompanyService $companyService,
        RestoreDocumentAction $restoreDocument,
    ): Response {
        $company = $companyService->getActiveCompany();
        $document = $repo->findByIdAndCompany($id, (string) $company->getId());

        if (null === $document || !$document->isDeleted()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('document_restore'.$id, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Неверный CSRF токен.');

            return $this->redirectToRoute('document_deleted_index');
        }

        try {
            $restoreDocument((string) $company->getId(), $id);
            $this->addFlash('success', 'Документ ОПиУ восстановлен.');
        } catch (\DomainException $exception) {
            $this->addFlash('danger', $exception->getMessage());
        }

        return $this->redirectToRoute('document_deleted_index');
    }

    /**
     * @return array{value: string|null, label: string, badgeClass: string}
     */
    private function buildNatureView(PlNature|string|null $nature): array
    {
        if ($nature instanceof PlNature) {
            return [
                'value' => $nature->value,
                'label' => PlNature::INCOME === $nature ? 'Доход' : 'Расход',
                'badgeClass' => PlNature::INCOME === $nature ? 'bg-green-lt text-green' : 'bg-red-lt text-red',
            ];
        }

        if ('MIXED' === $nature) {
            return [
                'value' => 'MIXED',
                'label' => 'Mixed',
                'badgeClass' => 'bg-purple-lt text-purple',
            ];
        }

        return [
            'value' => $nature ?? 'UNKNOWN',
            'label' => 'Неизвестно',
            'badgeClass' => 'bg-secondary',
        ];
    }

    private function duplicateDocument(Document $source, Company $company): Document
    {
        $copy = new Document(Uuid::uuid4()->toString(), $company);
        $copy->setDate($source->getDate());
        $copy->setNumber($source->getNumber());
        $copy->setType($source->getType());
        $copy->setCounterparty($source->getCounterparty());
        $copy->setProjectDirection($source->getProjectDirection());
        $copy->setResponsibilityCenterId($source->getResponsibilityCenterId());
        $copy->setDescription($source->getDescription());
        $copy->setStatus($source->getStatus());

        foreach ($source->getOperations() as $operation) {
            $operationCopy = new DocumentOperation();
            $operationCopy->setPlCategory($operation->getPlCategory());
            $operationCopy->setAmount($operation->getAmount());
            $operationCopy->setCounterparty($operation->getCounterparty());
            $operationCopy->setProjectDirection($operation->getProjectDirection());
            $operationCopy->setResponsibilityCenterId($operation->getResponsibilityCenterId());
            $operationCopy->setComment($operation->getComment());
            $copy->addOperation($operationCopy);
        }

        return $copy;
    }

    private function buildTransactionAllocationView(Document $document): ?array
    {
        $transaction = $document->getCashTransaction();

        if (!$transaction instanceof CashTransaction) {
            return null;
        }

        $transaction->recalculateAllocatedAmount();

        return [
            'transactionAmount' => (float) $transaction->getAmount(),
            'allocatedAmount' => $transaction->getAllocatedAmount(),
            'remainingAmount' => $transaction->getRemainingAmount(),
            // Сколько доступно этому документу: остаток без его собственных операций — по нему
            // страница пересчитывает остаток на лету, пока операции правят (истина — сервер).
            'availableForDocument' => $transaction->getRemainingAmount($document),
        ];
    }

    private function denyDeletedDocument(Document $document): void
    {
        if ($document->isDeleted()) {
            throw $this->createNotFoundException();
        }
    }
}
