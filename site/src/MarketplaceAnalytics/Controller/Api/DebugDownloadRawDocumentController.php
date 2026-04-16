<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Debug-эндпоинт для скачивания бинарного raw-документа.
 *
 * Декодирует base64 из raw_data.content_base64 и отдаёт файл
 * с правильным Content-Type и Content-Disposition.
 *
 * Использование:
 *   GET /api/marketplace-analytics/debug/raw-document/{id}/download
 */
#[Route(
    path: '/api/marketplace-analytics/debug/raw-document/{id}/download',
    name: 'api_marketplace_analytics_debug_raw_document_download',
    methods: ['GET'],
)]
#[IsGranted('ROLE_COMPANY_USER')]
final class DebugDownloadRawDocumentController extends AbstractController
{
    private const EXTENSION_MAP = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-excel' => 'xls',
        'text/csv' => 'csv',
        'application/csv' => 'csv',
        'application/zip' => 'zip',
        'application/octet-stream' => 'bin',
    ];

    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly MarketplaceRawDocumentRepository $rawDocumentRepository,
    ) {
    }

    public function __invoke(string $id): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $document = $this->rawDocumentRepository->find($id);

        if (null === $document || (string) $document->getCompany()->getId() !== $companyId) {
            throw $this->createNotFoundException('Raw-документ не найден.');
        }

        $rawData = $document->getRawData();

        if ((empty($rawData['_binary']) && empty($rawData['_text'])) || !isset($rawData['content_base64'])) {
            throw $this->createNotFoundException('Документ не содержит данных для скачивания.');
        }

        $contentType = $rawData['content_type'] ?? 'application/octet-stream';
        $content = base64_decode($rawData['content_base64'], true);

        if (false === $content) {
            throw new \RuntimeException('Не удалось декодировать base64.');
        }

        $extension = self::EXTENSION_MAP[$contentType] ?? 'bin';
        $periodFrom = $document->getPeriodFrom()->format('Y-m');
        $filename = sprintf('mutual_settlement_%s.%s', $periodFrom, $extension);

        return new Response($content, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            'Content-Length' => (string) strlen($content),
        ]);
    }
}
