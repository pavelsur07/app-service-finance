<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Controller;

use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use App\Shared\Service\ActiveCompanyService;
use App\Shared\Service\Storage\StorageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Отдаёт raw-файл отчёта Ozon (csv/zip), сохранённый через StorageService.
 *
 * Используется UI «История загрузок» — кнопкой «Открыть» на странице
 * «Реклама маркетплейсов» (task-8, 23.04.2026: парсинг временно отключён,
 * оператор получает сырую выгрузку напрямую).
 *
 * IDOR-защита: `findByIdAndCompany($id, $companyId)` отфильтрует документы
 * чужой company → 404. companyId берётся через {@see ActiveCompanyService},
 * как и в остальных контроллерах модуля.
 */
#[IsGranted('ROLE_COMPANY_USER')]
final class AdRawDocumentDownloadController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly AdRawDocumentRepository $rawDocumentRepository,
        private readonly StorageService $storageService,
    ) {
    }

    #[Route(
        '/marketplace-ads/raw-documents/{id}/download',
        name: 'marketplace_ads_raw_document_download',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function __invoke(string $id): BinaryFileResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $document = $this->rawDocumentRepository->findByIdAndCompany($id, $companyId);
        if (null === $document) {
            throw new NotFoundHttpException('Raw document not found');
        }

        $storagePath = $document->getStoragePath();
        if (null === $storagePath) {
            throw new NotFoundHttpException('Raw document has no stored file');
        }

        $absolutePath = $this->storageService->getAbsolutePath($storagePath);
        if (!is_file($absolutePath)) {
            throw new NotFoundHttpException('File missing on disk');
        }

        $extension = pathinfo($storagePath, \PATHINFO_EXTENSION) ?: 'bin';
        $filename = sprintf(
            'ozon-ad-%s.%s',
            $document->getReportDate()->format('Y-m-d'),
            $extension,
        );

        $response = new BinaryFileResponse($absolutePath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename,
        );

        return $response;
    }
}
