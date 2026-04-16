<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Shared\Service\ActiveCompanyService;
use App\Shared\Service\Storage\StorageService;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Debug-эндпоинт для просмотра структуры XLSX-файла raw-документа.
 *
 * Показывает содержимое всех непустых ячеек — помогает понять
 * формат отчёта и настроить якоря парсера.
 *
 * Использование:
 *   GET /api/marketplace-analytics/debug/raw-document/{id}/xlsx-dump
 */
#[Route(
    path: '/api/marketplace-analytics/debug/raw-document/{id}/xlsx-dump',
    name: 'api_marketplace_analytics_debug_raw_document_xlsx_dump',
    methods: ['GET'],
)]
#[IsGranted('ROLE_COMPANY_USER')]
final class DebugXlsxDumpController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly MarketplaceRawDocumentRepository $rawDocumentRepository,
        private readonly StorageService $storageService,
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
            return $this->json(['error' => 'Документ не содержит file_path'], 422);
        }

        $absolutePath = $this->storageService->getAbsolutePath($rawData['file_path']);

        if (!file_exists($absolutePath)) {
            return $this->json(['error' => 'Файл не найден на диске: ' . $rawData['file_path']], 404);
        }

        $reader = IOFactory::createReaderForFile($absolutePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($absolutePath);
        $sheet = $spreadsheet->getActiveSheet();

        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();
        $maxCol = Coordinate::columnIndexFromString($highestColumn);

        $rows = [];

        for ($row = 1; $row <= $highestRow; $row++) {
            $cells = [];

            for ($col = 1; $col <= $maxCol; $col++) {
                $colLetter = Coordinate::stringFromColumnIndex($col);
                $value = $sheet->getCell($colLetter . $row)->getCalculatedValue();

                if (null !== $value && '' !== $value) {
                    $cells[$colLetter] = $value;
                }
            }

            if (!empty($cells)) {
                $rows[] = [
                    'row' => $row,
                    'cells' => $cells,
                ];
            }
        }

        $spreadsheet->disconnectWorksheets();

        return $this->json([
            'documentId' => $id,
            'filePath' => $rawData['file_path'],
            'sheetTitle' => $sheet->getTitle(),
            'totalRows' => $highestRow,
            'highestColumn' => $highestColumn,
            'rows' => $rows,
        ]);
    }
}
