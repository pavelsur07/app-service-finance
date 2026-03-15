<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceListingBarcode;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Api\Ozon\OzonProductBarcodeFetcher;
use App\Marketplace\Message\SyncOzonListingBarcodesMessage;
use App\Marketplace\Repository\MarketplaceListingBarcodeRepository;
use App\Marketplace\Repository\MarketplaceListingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Синхронизирует баркоды и артикул продавца для всех Ozon-листингов компании.
 *
 * Стратегия:
 *   1. Загружаем все Ozon-листинги компании
 *   2. Запрашиваем данные у Ozon API /v3/product/info/list батчами по 1000 SKU
 *   3. Сохраняем новые баркоды (существующие пропускаем — uniq constraint)
 *   4. Обновляем supplier_sku листинга из offer_id если не заполнен
 */
#[AsMessageHandler]
final class SyncOzonListingBarcodesHandler
{
    public function __construct(
        private readonly OzonProductBarcodeFetcher           $barcodeFetcher,
        private readonly MarketplaceListingRepository        $listingRepository,
        private readonly MarketplaceListingBarcodeRepository $barcodeRepository,
        private readonly EntityManagerInterface              $em,
        private readonly LoggerInterface                     $logger,
    ) {
    }

    public function __invoke(SyncOzonListingBarcodesMessage $message): void
    {
        $companyId = $message->companyId;

        $this->logger->info('[OzonBarcodeSync] Started', ['company_id' => $companyId]);

        $listings = $this->listingRepository->findByCompanyIdAndMarketplace(
            $companyId,
            MarketplaceType::OZON,
        );

        if (empty($listings)) {
            $this->logger->info('[OzonBarcodeSync] No listings found', ['company_id' => $companyId]);
            return;
        }

        // Индексируем по SKU → listing object
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

        // Запрашиваем данные у Ozon API
        // fetchBarcodesBySkus возвращает ['sku' => ['barcode', 'offer_id']]
        // используем расширенный метод чтобы получить offer_id
        $skuToData = $this->barcodeFetcher->fetchProductDataBySkus($companyId, $skus);

        $barcodesCreated  = 0;
        $barcodesSkipped  = 0;
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

            // Сохраняем баркоды
            foreach ($data['barcodes'] as $barcode) {
                if ($barcode === '') {
                    continue;
                }

                if ($this->barcodeRepository->existsForCompany($companyId, $barcode)) {
                    $barcodesSkipped++;
                    continue;
                }

                $barcodeEntity = new MarketplaceListingBarcode(
                    Uuid::uuid4()->toString(),
                    $listing,
                    $companyId,
                    $barcode,
                );

                $this->em->persist($barcodeEntity);
                $barcodesCreated++;
            }
        }

        $this->em->flush();

        $this->logger->info('[OzonBarcodeSync] Completed', [
            'company_id'           => $companyId,
            'barcodes_created'     => $barcodesCreated,
            'barcodes_skipped'     => $barcodesSkipped,
            'supplier_sku_updated' => $supplierSkuUpdated,
        ]);
    }
}
