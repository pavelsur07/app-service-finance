<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\Marketplace\Application\Processor\OzonMutualSettlementProcessor;
use App\Marketplace\Enum\PipelineStep;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Shared\Service\ActiveCompanyService;
use App\Shared\Service\Storage\StorageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Debug-эндпоинт для переобработки (reparse) бинарного raw-документа mutual settlement.
 *
 * Берёт файл с диска (raw_data.file_path), повторно парсит через OzonMutualSettlementProcessor,
 * обновляет raw_data.parsed и статус документа.
 *
 * Использование:
 *   POST /api/marketplace-analytics/debug/raw-document/{id}/reparse
 */
#[Route(
    path: '/api/marketplace-analytics/debug/raw-document/{id}/reparse',
    name: 'api_marketplace_analytics_debug_raw_document_reparse',
    methods: ['POST'],
)]
#[IsGranted('ROLE_COMPANY_USER')]
final class DebugReparseMutualSettlementController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly MarketplaceRawDocumentRepository $rawDocumentRepository,
        private readonly OzonMutualSettlementProcessor $processor,
        private readonly StorageService $storageService,
        private readonly EntityManagerInterface $em,
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

        $rawData = $document->getRawData();

        if (!isset($rawData['file_path'])) {
            return $this->json([
                'success' => false,
                'error' => 'Документ не содержит file_path — переобработка невозможна.',
            ], 422);
        }

        $absolutePath = $this->storageService->getAbsolutePath($rawData['file_path']);

        if (!file_exists($absolutePath)) {
            return $this->json([
                'success' => false,
                'error' => 'Файл не найден на диске: ' . $rawData['file_path'],
            ], 404);
        }

        try {
            $parsed = $this->processor->parse($absolutePath);

            $rawData['parsed'] = $parsed;
            unset($rawData['parsing_error']);
            $document->setRawData($rawData);
            $document->setRecordsCount($parsed['meta']['rows_parsed'] ?? $parsed['totals']['rows_count'] ?? 0);
            $document->resetProcessingStatus();
            $document->markCompleted();
            $document->addSyncNote('Reparsed at ' . (new \DateTimeImmutable())->format('Y-m-d H:i:s'));

            $this->em->flush();

            return $this->json([
                'success' => true,
                'rawDocumentId' => $document->getId(),
                'rows_parsed' => $parsed['meta']['rows_parsed'] ?? 0,
                'parsed' => $parsed,
            ]);
        } catch (\Throwable $e) {
            $rawData['parsing_error'] = $e->getMessage();
            $document->setRawData($rawData);
            $document->resetProcessingStatus();
            $document->markStepFailed(PipelineStep::COSTS);
            $document->addSyncNote('Reparse failed: ' . $e->getMessage());

            $this->em->flush();

            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
