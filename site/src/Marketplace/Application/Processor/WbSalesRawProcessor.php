<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Company\Entity\Company;
use App\Marketplace\Application\ProcessWbSalesAction;
use App\Marketplace\Application\Service\MarketplaceBarcodeCatalogService;
use App\Marketplace\Application\Service\WbListingResolverService;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
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
    public function processBatch(string $companyId, MarketplaceType $marketplace, array $rawRows): void
    {
        if (empty($rawRows)) {
            return;
        }

        $company = $this->em->find(Company::class, $companyId);
        if (!$company instanceof Company) {
            throw new \RuntimeException('Company not found: ' . $companyId);
        }

        $salesData = array_filter($rawRows, static function (array $item): bool {
            return ($item['doc_type_name'] ?? '') === 'Продажа'
                && (float) ($item['retail_amount'] ?? 0) > 0;
        });

        if (empty($salesData)) {
            return;
        }

        // Заполняем каталог barcodes из продаж (barcode+ts_name известны)
        $this->barcodeCatalog->fillFromWbRows($companyId, array_values($salesData));

        $allNmIds = array_values(array_unique(array_column($salesData, 'nm_id')));
        $listingsCache = $this->listingRepository->findListingsByNmIdsIndexed(
            $company,
            MarketplaceType::WILDBERRIES,
            $allNmIds,
        );

        $newListings = 0;
        foreach ($salesData as $item) {
            $nmId = (string) ($item['nm_id'] ?? '');
            $tsName = $item['ts_name'] ?? null;
            $size = trim((string) $tsName) !== '' ? trim((string) $tsName) : 'UNKNOWN';
            $cacheKey = $nmId . '_' . $size;

            if (isset($listingsCache[$cacheKey])) {
                continue;
            }

            $barcode = (string) ($item['barcode'] ?? '');
            $listing = $this->listingResolver->resolve($company, $nmId, $tsName, [
                'sa_name'      => (string) ($item['sa_name'] ?? ''),
                'brand_name'   => (string) ($item['brand_name'] ?? ''),
                'subject_name' => (string) ($item['subject_name'] ?? ''),
                'retail_price' => (string) ($item['retail_price'] ?? '0'),
            ], $barcode);
            $listingsCache[$cacheKey] = $listing;
            $newListings++;
        }

        if ($newListings > 0) {
            $this->em->flush();
        }

        $allSrids = array_values(array_filter(array_column($salesData, 'srid')));
        $existingMap = $this->saleRepository->getExistingExternalIds($companyId, $allSrids);

        foreach ($salesData as $item) {
            $srid = (string) ($item['srid'] ?? '');
            if ($srid === '' || isset($existingMap[$srid])) {
                continue;
            }

            $nmId = (string) ($item['nm_id'] ?? '');
            $tsName = $item['ts_name'] ?? null;
            $size = trim((string) $tsName) !== '' ? trim((string) $tsName) : 'UNKNOWN';
            $listing = $listingsCache[$nmId . '_' . $size] ?? null;

            if (!$listing) {
                $this->logger->warning('[WB] processBatch sales: listing not found', ['nm_id' => $nmId]);
                continue;
            }

            $sale = new MarketplaceSale(
                Uuid::uuid4()->toString(),
                $company,
                $listing,
                MarketplaceType::WILDBERRIES,
            );

            $sale->setExternalOrderId($srid);
            $sale->setSaleDate(new \DateTimeImmutable($item['sale_dt'] ?? $item['rr_dt']));
            $sale->setQuantity(abs((int) ($item['quantity'] ?? 1)));
            $sale->setPricePerUnit((string) ($item['retail_price'] ?? '0'));
            $sale->setTotalRevenue((string) abs((float) ($item['retail_amount'] ?? 0)));
            $sale->setRawData($item);

            $this->em->persist($sale);
            $existingMap[$srid] = true;
        }

        $this->em->flush();
    }
}
