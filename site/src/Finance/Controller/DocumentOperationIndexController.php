<?php

declare(strict_types=1);

namespace App\Finance\Controller;

use App\Company\Facade\CompanyFacade;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\Finance\Application\Service\DocumentPlContribution;
use App\Finance\DTO\DocumentListDTO;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Repository\DocumentOperationRepository;
use App\Finance\Repository\PLCategoryRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Вкладка «Операции» раздела «Документы ОПиУ»: плоский список операций документов
 * с теми же фильтрами, что у вкладки документов.
 */
#[IsGranted('ROLE_USER')]
final class DocumentOperationIndexController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly DocumentOperationRepository $operationRepository,
        private readonly DocumentPlContribution $plContribution,
        private readonly CompanyFacade $companyFacade,
        private readonly FinancialResponsibilityCenterFacade $responsibilityCenterFacade,
        private readonly PLCategoryRepository $categoryRepository,
    ) {
    }

    // Приоритет выше `/documents/{id}`: у роутов документа id без требования формата,
    // и без приоритета «operations» ушло бы туда как id документа.
    #[Route('/documents/operations', name: 'document_operation_index', methods: ['GET'], priority: 10)]
    public function __invoke(Request $request): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();
        $filters = DocumentListDTO::fromQuery($company, $request->query);

        $pager = $this->operationRepository->paginateForList($companyId, $filters);
        /** @var list<DocumentOperation> $items */
        $items = array_values(iterator_to_array($pager->getCurrentPageResults()));

        $documentIds = [];
        $responsibilityCenterIds = [];
        foreach ($items as $operation) {
            $documentIds[] = (string) $operation->getDocument()?->getId();
            $responsibilityCenterIds[] = $operation->getResponsibilityCenterId() ?? $operation->getDocument()?->getResponsibilityCenterId();
        }
        $documentIds = array_values(array_unique($documentIds));

        $bucketSums = $this->operationRepository->marketplaceBucketSums($companyId, $documentIds);
        $contributions = [];
        foreach ($items as $operation) {
            $contributions[(string) $operation->getId()] = $this->plContribution->forOperation($operation, $bucketSums);
        }

        return $this->render('document/operations.html.twig', [
            'items' => $items,
            'contributions' => $contributions,
            'operationCounts' => $this->operationRepository->countByDocuments($companyId, $documentIds),
            'pager' => $pager,
            'limit' => $pager->getMaxPerPage(),
            'filters' => $filters,
            'totals' => $this->operationRepository->summarizeForList($companyId, $filters),
            'responsibilityCenterNames' => $this->responsibilityCenterFacade->getNamesByIds(
                $companyId,
                array_values(array_unique(array_filter($responsibilityCenterIds, static fn (?string $id): bool => null !== $id))),
            ),
            'projectDirections' => $this->companyFacade->listProjectDirectionEntities($companyId, true),
            'responsibilityCenters' => $this->responsibilityCenterFacade->getActiveChoices($companyId),
            'categories' => $this->categoryRepository->findTreeByCompany($company),
        ]);
    }
}
