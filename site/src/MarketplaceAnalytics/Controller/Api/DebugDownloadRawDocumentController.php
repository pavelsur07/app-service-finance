<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Shared\Service\ActiveCompanyService;
use App\Shared\Service\Storage\ObjectStorageInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Debug-эндпоинт для скачивания бинарного raw-документа.
 *
 * Поддерживает два формата хранения:
 *   1. Файл на диске (raw_data.file_path) — новый формат
 *   2. Base64 в raw_data (legacy) — обратная совместимость
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
        private readonly ObjectStorageInterface $storage,
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
        $periodFrom = $document->getPeriodFrom()->format('Y-m');

        // Новый формат: файл на диске
        if (isset($rawData['file_path'])) {
            return $this->serveFromDisk($rawData, $periodFrom);
        }

        // Legacy: base64 в raw_data
        if ((!empty($rawData['_binary']) || !empty($rawData['_text'])) && isset($rawData['content_base64'])) {
            return $this->serveFromBase64($rawData, $periodFrom);
        }

        throw $this->createNotFoundException('Документ не содержит данных для скачивания.');
    }

    private function serveFromDisk(array $rawData, string $periodFrom): Response
    {
        $storagePath = $rawData['file_path'];

        if (!$this->storage->exists($storagePath)) {
            throw $this->createNotFoundException('Файл не найден в хранилище.');
        }

        $contentType = $rawData['content_type'] ?? 'application/octet-stream';
        $extension = self::EXTENSION_MAP[$contentType] ?? pathinfo($storagePath, \PATHINFO_EXTENSION) ?: 'bin';
        $filename = sprintf('mutual_settlement_%s.%s', $periodFrom, $extension);

        $stream = $this->storage->readStream($storagePath);
        $response = new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        });
        $response->headers->set('Content-Type', $contentType);
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename),
        );

        return $response;
    }

    private function serveFromBase64(array $rawData, string $periodFrom): Response
    {
        $contentType = $rawData['content_type'] ?? 'application/octet-stream';
        $content = base64_decode($rawData['content_base64'], true);

        if (false === $content) {
            throw new \RuntimeException('Не удалось декодировать base64.');
        }

        $extension = self::EXTENSION_MAP[$contentType] ?? 'bin';
        $filename = sprintf('mutual_settlement_%s.%s', $periodFrom, $extension);

        return new Response($content, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            'Content-Length' => (string) strlen($content),
        ]);
    }
}
