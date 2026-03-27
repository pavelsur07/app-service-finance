<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Marketplace\Entity\MarketplaceJobLog;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceListingBarcode;
use App\Marketplace\Enum\JobType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Api\Ozon\OzonProductBarcodeFetcher;
use App\Marketplace\Message\SyncOzonListingBarcodesMessage;
use App\Marketplace\Repository\MarketplaceJobLogRepository;
use App\Marketplace\Repository\MarketplaceListingBarcodeRepository;
use App\Marketplace\Repository\MarketplaceListingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SyncOzonListingBarcodesHandler
{
    public function __construct(
        private readonly OzonProductBarcodeFetcher           $barcodeFetcher,
        private readonly MarketplaceListingRepository        $listingRepository,
        private readonly MarketplaceListingBarcodeRepository $barcodeRepository,
        private readonly MarketplaceJobLogRepository         $jobLogRepository,
        private readonly EntityManagerInterface              $em,
        private readonly LoggerInterface                     $logger,
    ) {
    }

    public function __invoke(SyncOzonListingBarcodesMessage $message): void
    {
        $companyId = $message->companyId;

        // Создаём запись лога со статусом running
        $jobLog = new MarketplaceJobLog(
            Uuid::uuid4()->toString(),
            $companyId,
            JobType::BARCODE_SYNC_OZON,
        );
        $this->jobLogRepository->save($jobLog);

        $this->logger->info('[OzonBarcodeSync] Started', ['company_id' => $companyId]);

        try {
            $listings = $this->listingRepository->findByCompanyIdAndMarketplace(
                $companyId,
                MarketplaceType::OZON,
            );

            if (empty($listings)) {
                $jobLog->complete(['created' => 0, 'skipped' => 0, 'errors' => 0]);
                $this->jobLogRepository->save($jobLog);
                return;
            }

            /** @var array<string, MarketplaceListing> $skuToListing */
            $skuToListing = [];
            foreach ($listings as $listing) {
                $skuToListing[$listing->getMarketplaceSku()] = $listing;
            }

            $skus = array_keys($skuToListing);

            $this->logger->info('[OzonBarcodeSync] Fetching barcodes from API', [
                'company_id' => $companyId,
                'skus_count' => count($skus),
            ]);

            $skuToData = $this->barcodeFetcher->fetchProductDataBySkus($companyId, $skus);

            // Логируем SKU без данных от API
            $skusNotFound = array_diff($skus, array_keys($skuToData));
            $details      = [];
            foreach ($skusNotFound as $missingSku) {
                $listing   = $skuToListing[$missingSku];
                $details[] = [
                    'sku'    => $missingSku,
                    'name'   => $listing->getName(),
                    'reason' => 'SKU не найден в Ozon API',
                ];
                $this->logger->warning('[OzonBarcodeSync] SKU not found in API', [
                    'company_id' => $companyId,
                    'sku'        => $missingSku,
                ]);
            }

            $barcodesCreated    = 0;
            $barcodesSkipped    = 0;
            $supplierSkuUpdated = 0;

            foreach ($skuToData as $sku => $data) {
                $listing = $skuToListing[(string) $sku] ?? null;
                if ($listing === null) {
                    continue;
                }

                // Обновляем supplier_sku из offer_id если не заполнен
                $offerId = $data['offer_id'] ?? null;
                if ($offerId !== null && $offerId !== '' && $listing->getSupplierSku() === null) {
                    $listing->setSupplierSku($offerId);
                    $supplierSkuUpdated++;
                }

                foreach ($data['barcodes'] as $barcode) {
                    if ($barcode === '') {
                        continue;
                    }

                    if ($this->barcodeRepository->existsForCompanyAndMarketplace($companyId, MarketplaceType::OZON, $barcode)) {
                        $barcodesSkipped++;
                        continue;
                    }

                    $barcodeEntity = new MarketplaceListingBarcode(
                        Uuid::uuid4()->toString(),
                        $listing,
                        $companyId,
                        MarketplaceType::OZON->value,
                        $barcode,
                    );
                    $this->em->persist($barcodeEntity);
                    $barcodesCreated++;
                }
            }

            $this->em->flush();

            $summary = [
                'total_skus'          => count($skus),
                'barcodes_created'    => $barcodesCreated,
                'barcodes_skipped'    => $barcodesSkipped,
                'supplier_sku_updated'=> $supplierSkuUpdated,
                'errors'              => count($details),
            ];

            $jobLog->complete($summary, $details);
            $this->jobLogRepository->save($jobLog);

            $this->logger->info('[OzonBarcodeSync] Completed', array_merge(
                ['company_id' => $companyId],
                $summary,
            ));
        } catch (\Throwable $e) {
            $jobLog->fail($e->getMessage());
            $this->jobLogRepository->save($jobLog);

            $this->logger->error('[OzonBarcodeSync] Failed', [
                'company_id' => $companyId,
                'error'      => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
