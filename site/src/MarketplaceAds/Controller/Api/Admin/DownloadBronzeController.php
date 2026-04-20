<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Controller\Api\Admin;

use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use App\Shared\Service\Storage\StorageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Скачивание сырого bronze-файла рекламного отчёта Ozon (ZIP или CSV).
 *
 * Endpoint только для super-admin'ов (общая инфраструктурная диагностика —
 * IDOR-проверка по company_id здесь намеренно отключена, как и у соседнего
 * /api/marketplace-ads/admin/logs). Для rank-and-file пользователей bronze
 * остаётся недоступным, прямой URL — единственный способ получить файл.
 */
#[Route(
    '/api/marketplace-ads/admin/bronze/{documentId}/download',
    name: 'marketplace_ads_admin_bronze_download',
    methods: ['GET'],
)]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class DownloadBronzeController extends AbstractController
{
    public function __construct(
        private readonly AdRawDocumentRepository $adRawDocumentRepository,
        private readonly StorageService $storageService,
    ) {
    }

    public function __invoke(string $documentId): Response
    {
        $document = $this->adRawDocumentRepository->find($documentId);
        if (null === $document) {
            throw $this->createNotFoundException('AdRawDocument not found');
        }

        $storagePath = $document->getStoragePath();
        if (null === $storagePath) {
            // Старые документы (до миграции на bronze) не имеют сохранённого
            // raw-файла — возвращаем 404 с явным текстом вместо общего «not found»,
            // чтобы оператор понял причину.
            throw $this->createNotFoundException('Bronze file missing: document has no storage_path');
        }

        $absolutePath = $this->storageService->getAbsolutePath($storagePath);
        if (!is_file($absolutePath)) {
            throw $this->createNotFoundException('Bronze file missing: file not found on disk');
        }

        $extension = str_ends_with(strtolower($storagePath), '.zip') ? 'zip' : 'csv';
        $contentType = 'zip' === $extension ? 'application/zip' : 'text/csv';
        $filename = basename($storagePath);

        $response = new StreamedResponse(static function () use ($absolutePath): void {
            readfile($absolutePath);
        });
        $response->headers->set('Content-Type', $contentType);
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                $filename,
            ),
        );

        $sizeBytes = $document->getFileSizeBytes();
        if (null !== $sizeBytes) {
            $response->headers->set('Content-Length', (string) $sizeBytes);
        }

        return $response;
    }
}
