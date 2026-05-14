<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Company\Entity\Company;
use App\Marketplace\Application\ProcessWbSalesAction;
use App\Marketplace\Application\Service\MarketplaceBarcodeCatalogService;
use App\Marketplace\Application\Service\MarketplaceCostPriceResolver;
use App\Marketplace\Application\Service\WbListingResolverService;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Infrastructure\Normalizer\Wildberries\WbSalesReportRowNormalizer;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Marketplace\Repository\MarketplaceSaleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

final class WbSalesRawProcessor implements MarketplaceRawProcessorInterface
{
    public function __construct(
        private readonly ProcessWbSalesAction $action,
        private readonly EntityManagerInterface $em,
        private readonly MarketplaceSaleRepository $saleRepository,
        private readonly MarketplaceListingRepository $listingRepository,
        private readonly WbListingResolverService $listingResolver,
        private readonly MarketplaceBarcodeCatalogService $barcodeCatalog,
        private readonly MarketplaceCostPriceResolver $costPriceResolver,
        private readonly WbSalesReportRowNormalizer $normalizer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(string|StagingRecordType $type, MarketplaceType $marketplace, string $kind = ''): bool
    {
        if ($type instanceof StagingRecordType) {
            return $type === StagingRecordType::SALE
                && $marketplace === MarketplaceType::WILDBERRIES;
        }

        return $type === MarketplaceType::WILDBERRIES->value && $kind === 'sales';
    }

    public function process(string $companyId, string $rawDocId): int
    {
        return ($this->action)($companyId, $rawDocId);
    }

    /**
     * @param array<int, array<string, mixed>> $rawRows
     */
    public function processBatch(
        string $companyId,
        MarketplaceType $marketplace,
        array $rawRows,
        ?string $rawDocId = null,
    ): void {
        if (empty($rawRows)) {
            return;
        }

        $company = $this->em->find(Company::class, $companyId);
        if (!$company instanceof Company) {
            throw new \RuntimeException('Company not found: ' . $companyId);
        }

        $salesData = array_filter($rawRows, function (array $item): bool {
            return $this->normalizer->isSale($item)
                && $this->normalizer->quantity($item) > 0
                && $this->normalizer->retailPriceWithDisc($item) > 0;
        });

        if (empty($salesData)) {
            return;
        }

        $this->barcodeCatalog->fillFromWbRows($companyId, array_values($salesData));

        $allNmIds = array_values(array_unique(array_map(
            fn (array $item): string => $this->normalizer->nmId($item),
            $salesData,
        )));
        $listingsCache = $this->listingRepository->findListingsByNmIdsIndexed(
            $company,
            MarketplaceType::WILDBERRIES,
            $allNmIds,
        );

        $newListings = 0;
        foreach ($salesData as $item) {
            $nmId = $this->normalizer->nmId($item);
            $tsName = $this->normalizer->techSize($item);
            $size = trim((string) $tsName) !== '' ? trim((string) $tsName) : 'UNKNOWN';
            $cacheKey = $nmId . '_' . $size;

            if (isset($listingsCache[$cacheKey])) {
                continue;
            }

            $barcode = (string) ($this->normalizer->barcode($item) ?? '');
            $listing = $this->listingResolver->resolve($company, $nmId, $tsName, [
                'sa_name'      => $this->normalizer->vendorCode($item),
                'brand_name'   => (string) ($item['brand_name'] ?? $item['brandName'] ?? ''),
                'subject_name' => (string) ($item['subject_name'] ?? $item['subjectName'] ?? ''),
                'retail_price' => (string) ($item['retail_price'] ?? $item['retailPrice'] ?? '0'),
            ], $barcode);
            $listingsCache[$cacheKey] = $listing;
            $newListings++;
        }

        if ($newListings > 0) {
            $this->em->flush();
            // Баркоды вставляются после flush, чтобы FK на листинг был уже в БД
            $this->listingResolver->flushBarcodes();
        }

        $allSrids = array_values(array_filter(array_column($salesData, 'srid')));
        $existingMap = $this->saleRepository->getExistingExternalIds($companyId, $allSrids);

        foreach ($salesData as $item) {
            $srid = (string) ($item['srid'] ?? '');
            if ($srid === '' || isset($existingMap[$srid])) {
                continue;
            }

            $nmId = $this->normalizer->nmId($item);
            $tsName = $this->normalizer->techSize($item);
            $size = trim((string) $tsName) !== '' ? trim((string) $tsName) : 'UNKNOWN';
            $listing = $listingsCache[$nmId . '_' . $size] ?? null;

            if (!$listing) {
                $this->logger->warning('[WB] processBatch sales: listing not found', ['nm_id' => $nmId]);
                continue;
            }

            $saleDate = $this->normalizer->operationDate($item);

            $sale = new MarketplaceSale(
                Uuid::uuid4()->toString(),
                $company,
                $listing,
                MarketplaceType::WILDBERRIES,
            );

            $sale->setExternalOrderId($srid);
            $sale->setSaleDate($saleDate);
            $quantity = abs($this->normalizer->quantity($item));
            $retailPriceWithDisc = $this->normalizer->retailPriceWithDisc($item);
            $pricePerUnit = $quantity > 0 ? $retailPriceWithDisc / $quantity : 0.0;

            $sale->setQuantity($quantity);
            $sale->setPricePerUnit((string) $pricePerUnit);
            $sale->setTotalRevenue((string) $retailPriceWithDisc);
            $sale->setCostPrice($this->costPriceResolver->resolveForSale($listing, $saleDate));
            $sale->setRawData($item);
            if ($rawDocId !== null) {
                $sale->setRawDocumentId($rawDocId);
            }

            $this->em->persist($sale);
            $existingMap[$srid] = true;
        }

        $this->em->flush();
    }
}
