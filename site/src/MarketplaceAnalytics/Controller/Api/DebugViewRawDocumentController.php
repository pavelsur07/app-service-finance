<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Debug-эндпоинт для просмотра содержимого raw-документа.
 *
 * Возвращает полный raw_data как JSON для анализа структуры ответа Ozon API.
 *
 * Использование:
 *   GET /api/marketplace-analytics/debug/raw-document/{id}
 */
#[Route(
    path: '/api/marketplace-analytics/debug/raw-document/{id}',
    name: 'api_marketplace_analytics_debug_raw_document_view',
    methods: ['GET'],
)]
#[IsGranted('ROLE_COMPANY_USER')]
final class DebugViewRawDocumentController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly MarketplaceRawDocumentRepository $rawDocumentRepository,
    ) {
    }

    public function __invoke(string $id): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $document = $this->rawDocumentRepository->find($id);

        if (null === $document || (string) $document->getCompany()->getId() !== $companyId) {
            throw $this->createNotFoundException('Raw-документ не найден.');
        }

        return new JsonResponse([
            'id' => $document->getId(),
            'documentType' => $document->getDocumentType(),
            'marketplace' => $document->getMarketplace()->value,
            'periodFrom' => $document->getPeriodFrom()->format('Y-m-d'),
            'periodTo' => $document->getPeriodTo()->format('Y-m-d'),
            'syncedAt' => $document->getSyncedAt()->format('Y-m-d H:i:s'),
            'recordsCount' => $document->getRecordsCount(),
            'processingStatus' => $document->getProcessingStatus()?->value,
            'apiEndpoint' => $document->getApiEndpoint(),
            'rawData' => $document->getRawData(),
        ]);
    }
}
