<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Controller\Api;

use App\MarketplaceAds\Application\ReprocessAdRawDocumentAction;
use App\MarketplaceAds\Exception\AdRawDocumentNotFoundException;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    '/api/marketplace-ads/raw-documents/{id}/reprocess',
    name: 'marketplace_ads_raw_documents_reprocess',
    requirements: ['id' => '[0-9a-fA-F-]{36}'],
    methods: ['POST'],
)]
#[IsGranted('ROLE_COMPANY_USER')]
final class ReprocessAdRawDocumentController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly ReprocessAdRawDocumentAction $reprocessAction,
    ) {
    }

    public function __invoke(string $id): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        try {
            ($this->reprocessAction)($companyId, $id);
        } catch (AdRawDocumentNotFoundException) {
            return $this->json(['message' => 'Документ не найден.'], 404);
        }

        // Парсинг отключён (task-8): dispatch'а больше нет, API возвращает
        // 'reset_to_draft' — ровно то, что было сделано в БД.
        return $this->json([
            'status' => 'reset_to_draft',
            'documentId' => $id,
        ]);
    }
}
