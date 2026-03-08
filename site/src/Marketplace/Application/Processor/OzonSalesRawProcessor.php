<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Company\Entity\Company;
use App\Marketplace\Application\ProcessOzonSalesAction;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Marketplace\Repository\MarketplaceSaleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

final class OzonSalesRawProcessor implements MarketplaceRawProcessorInterface
{
    public function __construct(
        private readonly ProcessOzonSalesAction $action,
        private readonly EntityManagerInterface $em,
        private readonly MarketplaceSaleRepository $saleRepository,
        private readonly MarketplaceListingRepository $listingRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(string|StagingRecordType $type, MarketplaceType $marketplace, string $kind = ''): bool
    {
        if ($type instanceof StagingRecordType) {
            return $type === StagingRecordType::SALE
                && $marketplace === MarketplaceType::OZON;
        }

        return $type === MarketplaceType::OZON->value && $kind === 'sales';
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

        // Продажи: type=orders + accruals_for_sale > 0
        $salesData = array_filter($rawRows, static function (array $op): bool {
            return ($op['type'] ?? '') === 'orders'
                && (float) ($op['accruals_for_sale'] ?? 0) > 0;
        });

        if (empty($salesData)) {
            return;
        }

        // Собираем все SKU
        $allSkus = [];
        foreach ($salesData as $op) {
            foreach ($op['items'] ?? [] as $item) {
                $sku = (string) ($item['sku'] ?? '');
                if ($sku !== '') {
                    $allSkus[$sku] = true;
                }
            }
        }

        // Предзагрузка листингов — храним ID чтобы избежать detached proxy
        $listingsIdCache = [];
        if (!empty($allSkus)) {
            $listings = $this->listingRepository->findListingsBySkusIndexed(
                $company,
                MarketplaceType::OZON,
                array_keys($allSkus),
            );
            foreach ($listings as $sku => $listing) {
                $listingsIdCache[$sku] = $listing->getId();
            }
        }

        // Создаём отсутствующие листинги
        $newListings = 0;
        foreach ($salesData as $op) {
            foreach ($op['items'] ?? [] as $item) {
                $sku = (string) ($item['sku'] ?? '');
                if ($sku === '' || isset($listingsIdCache[$sku])) {
                    continue;
                }

                $listing = new MarketplaceListing(
                    Uuid::uuid4()->toString(),
                    $company,
                    null,
                    MarketplaceType::OZON,
                );
                $listing->setMarketplaceSku($sku);
                $listing->setName($item['name'] ?? null);
                $this->em->persist($listing);

                $listingsIdCache[$sku] = $listing->getId();
                $newListings++;
            }
        }

        if ($newListings > 0) {
            $this->em->flush();
        }

        // Дедупликация — ключ: posting_number ?: operation_id (как в OzonAdapter)
        $allExternalIds = array_values(array_map(
            static function (array $op): string {
                $postingNumber = $op['posting']['posting_number'] ?? '';
                return $postingNumber !== '' ? $postingNumber : (string) ($op['operation_id'] ?? '');
            },
            $salesData,
        ));
        $existingMap = $this->saleRepository->getExistingExternalIds($companyId, $allExternalIds);

        foreach ($salesData as $op) {
            $postingNumber = $op['posting']['posting_number'] ?? '';
            $externalId = $postingNumber !== '' ? $postingNumber : (string) ($op['operation_id'] ?? '');

            if ($externalId === '' || isset($existingMap[$externalId])) {
                continue;
            }

            $firstItem = ($op['items'] ?? [])[0] ?? null;
            $sku = $firstItem ? (string) ($firstItem['sku'] ?? '') : '';
            $listingId = $listingsIdCache[$sku] ?? null;

            if (!$listingId) {
                $this->logger->warning('[Ozon] processBatch sales: listing not found', [
                    'external_id' => $externalId,
                    'sku'         => $sku,
                ]);
                continue;
            }

            $listing = $this->em->getReference(MarketplaceListing::class, $listingId);
            $accrual = (float) ($op['accruals_for_sale'] ?? 0);

            $sale = new MarketplaceSale(
                Uuid::uuid4()->toString(),
                $company,
                $listing,
                MarketplaceType::OZON,
            );

            $sale->setExternalOrderId($externalId);
            $sale->setSaleDate(new \DateTimeImmutable($op['operation_date']));
            $sale->setQuantity(count($op['items'] ?? []) ?: 1);
            $sale->setPricePerUnit((string) $accrual);
            $sale->setTotalRevenue((string) $accrual);
            $sale->setRawData($op);

            $this->em->persist($sale);
            $existingMap[$externalId] = true;
        }

        $this->em->flush();
    }
}
