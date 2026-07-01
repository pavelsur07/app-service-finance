<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Controller;

use App\MarketplaceAds\Repository\AdScheduledBatchRepository;
use App\Shared\Service\ActiveCompanyService;
use App\Shared\Service\Storage\ObjectStorageInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Отдаёт raw-файл одного батча cron-driven pipeline (Task-11.8).
 *
 * Аналог {@see AdRawDocumentDownloadController} для Messenger-pipeline'а:
 * UI «История загрузок» рендерит по одной кнопке «Открыть batch N» на
 * каждый батч job'а со state=OK и непустым `storage_path` (см. collection
 * `AdLoadJobsListController` и {@see AdScheduledBatchRepository::findDownloadableByJobId()}).
 *
 * IDOR-защита: {@see AdScheduledBatchRepository::findByIdAndCompany()}
 * отфильтрует батч чужой company → 404. companyId берётся через
 * {@see ActiveCompanyService}, как и в остальных контроллерах модуля.
 */
#[IsGranted('ROLE_COMPANY_USER')]
final class AdScheduledBatchDownloadController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly AdScheduledBatchRepository $batchRepository,
        private readonly ObjectStorageInterface $storage,
    ) {
    }

    #[Route(
        '/marketplace-ads/batches/{id}/download',
        name: 'marketplace_ads_batch_download',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function __invoke(string $id): StreamedResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $batch = $this->batchRepository->findByIdAndCompany($id, $companyId);
        if (null === $batch) {
            // Не различаем «не существует» и «чужая company» — не утекает
            // информация о существовании чужих батчей.
            throw new NotFoundHttpException('Batch not found');
        }

        $storagePath = $batch->getStoragePath();
        if (null === $storagePath) {
            throw new NotFoundHttpException('Batch has no stored file');
        }

        if (!$this->storage->exists($storagePath)) {
            throw new NotFoundHttpException('File missing in storage');
        }

        $extension = pathinfo($storagePath, \PATHINFO_EXTENSION) ?: 'bin';
        $filename = sprintf(
            'ozon-ad-batch-%d-%s_%s.%s',
            $batch->getBatchIndex(),
            $batch->getDateFrom()->format('Y-m-d'),
            $batch->getDateTo()->format('Y-m-d'),
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

        $sizeBytes = $batch->getFileSize();
        if (null !== $sizeBytes) {
            $response->headers->set('Content-Length', (string) $sizeBytes);
        }

        return $response;
    }
}
