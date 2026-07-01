<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Controller;

use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use App\Shared\Service\ActiveCompanyService;
use App\Shared\Service\Storage\ObjectStorageInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Отдаёт raw-файл отчёта Ozon (csv/zip) из объектного хранилища.
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
        private readonly ObjectStorageInterface $storage,
    ) {
    }

    #[Route(
        '/marketplace-ads/raw-documents/{id}/download',
        name: 'marketplace_ads_raw_document_download',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function __invoke(string $id): StreamedResponse
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

        if (!$this->storage->exists($storagePath)) {
            throw new NotFoundHttpException('File missing in storage');
        }

        $extension = pathinfo($storagePath, \PATHINFO_EXTENSION) ?: 'bin';
        $filename = sprintf(
            'ozon-ad-%s.%s',
            $document->getReportDate()->format('Y-m-d'),
            $extension,
        );

        $stream = $this->storage->readStream($storagePath);
        $response = new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        });
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename),
        );

        return $response;
    }
}
