<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Company\Entity\Company;
use App\Marketplace\Application\Service\WbListingResolverService;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Api\Wildberries\WbProductCardsClient;
use App\Marketplace\Infrastructure\Query\WbBarcodeUpsertQuery;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Marketplace\Repository\MarketplaceListingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class RefreshWbListingCatalogAction
{
    public function __construct(
        private EntityManagerInterface $em,
        private MarketplaceConnectionRepository $connectionRepository,
        private WbProductCardsClient $client,
        private WbListingResolverService $listingResolver,
        private MarketplaceListingRepository $listingRepository,
        private WbBarcodeUpsertQuery $barcodeUpsertQuery,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(string $companyId, string $connectionId): int
    {
        $company = $this->em->find(Company::class, $companyId);
        if (!$company instanceof Company) {
            throw new \InvalidArgumentException('Company not found.');
        }

        $connection = $this->connectionRepository->findByIdAndCompany($connectionId, $company);
        $this->assertUsableConnection($connection);

        $activeCards = $this->client->fetchAll($connection->getApiKey());
        $trashCards = $this->client->fetchAllTrash($connection->getApiKey());
        $variants = $this->normalizeVariants($activeCards, $trashCards);

        $synced = $this->em->wrapInTransaction(function () use ($company, $variants): int {
            $resolved = [];
            $byVariant = [];
            foreach ($this->listingRepository->findAllByCompanyMarketplaceAndMarketplaceVariantIds(
                $company->getId(),
                MarketplaceType::WILDBERRIES,
                array_column($variants, 'chrtId'),
            ) as $listing) {
                $byVariant[$listing->getMarketplaceVariantId()] = $listing;
            }
            $byNaturalKey = $this->listingRepository->findListingsByNmIdsIndexed(
                $company,
                MarketplaceType::WILDBERRIES,
                array_values(array_unique(array_column($variants, 'nmId'))),
            );

            foreach ($variants as $variant) {
                $naturalKey = $variant['nmId'].'_'.$variant['size'];
                $listing = $this->listingResolver->resolveCatalogVariant(
                    company: $company,
                    nmId: $variant['nmId'],
                    size: $variant['size'],
                    marketplaceVariantId: $variant['chrtId'],
                    listingByNaturalKey: $byNaturalKey[$naturalKey] ?? null,
                    listingByVariant: $byVariant[$variant['chrtId']] ?? null,
                    wbMeta: [
                        'sa_name' => $variant['vendorCode'],
                        'brand_name' => $variant['brand'],
                        'subject_name' => $variant['subjectName'],
                    ],
                );
                $byNaturalKey[$naturalKey] = $listing;
                $byVariant[$variant['chrtId']] = $listing;

                if ('' !== $variant['vendorCode']) {
                    $listing->setSupplierSku($variant['vendorCode']);
                }
                if ('' !== $variant['title']) {
                    $listing->setName($variant['title']);
                }
                $listing->setIsActive($variant['isActive']);
                $resolved[] = [$listing, $variant['barcodes']];
            }

            $this->em->flush();
            foreach ($resolved as [$listing, $barcodes]) {
                foreach ($barcodes as $barcode) {
                    $this->barcodeUpsertQuery->upsertForListing($company->getId(), $listing->getId(), $barcode);
                }
            }

            return count($resolved);
        });

        $this->logger->info('WB listing catalog refreshed.', [
            'company_id' => $companyId,
            'connection_id' => $connectionId,
            'variants_synced' => $synced,
            'active_variants_synced' => count(array_filter($variants, static fn (array $variant): bool => $variant['isActive'])),
            'trash_variants_synced' => count(array_filter($variants, static fn (array $variant): bool => !$variant['isActive'])),
        ]);

        return $synced;
    }

    private function assertUsableConnection(?MarketplaceConnection $connection): void
    {
        if (
            !$connection instanceof MarketplaceConnection
            || !$connection->isActive()
            || MarketplaceType::WILDBERRIES !== $connection->getMarketplace()
            || MarketplaceConnectionType::SELLER !== $connection->getConnectionType()
        ) {
            throw new \InvalidArgumentException('Active Wildberries SELLER connection not found for company.');
        }
    }

    /**
     * @param list<array<string, mixed>> $activeCards
     * @param list<array<string, mixed>> $trashCards
     *
     * @return list<array{nmId: string, chrtId: string, size: string, vendorCode: string, brand: string, subjectName: string, title: string, barcodes: list<string>, isActive: bool}>
     */
    private function normalizeVariants(array $activeCards, array $trashCards): array
    {
        $variants = [];
        $naturalKeys = [];
        $barcodeOwners = [];

        foreach ([[true, $activeCards], [false, $trashCards]] as [$isActive, $cards]) {
            foreach ($cards as $card) {
                $nmId = $this->positiveId($card['nmID'] ?? null, 'nmID');
                $sizes = $card['sizes'] ?? null;
                if (!is_array($sizes) || !array_is_list($sizes)) {
                    throw new \UnexpectedValueException(sprintf('WB card nmID=%s has invalid sizes.', $nmId));
                }

                foreach ($sizes as $sizeData) {
                    if (!is_array($sizeData)) {
                        throw new \UnexpectedValueException(sprintf('WB card nmID=%s contains invalid size.', $nmId));
                    }

                    $chrtId = $this->positiveId($sizeData['chrtID'] ?? null, 'chrtID');
                    $size = trim((string) ($sizeData['techSize'] ?? '')) ?: 'UNKNOWN';
                    $naturalKey = $nmId."\0".$size;

                    if (isset($naturalKeys[$naturalKey]) && $naturalKeys[$naturalKey] !== $chrtId) {
                        throw new \DomainException(sprintf('WB nmID=%s size=%s has multiple chrtId values.', $nmId, $size));
                    }
                    if (isset($variants[$chrtId]) && ($variants[$chrtId]['nmId'] !== $nmId || $variants[$chrtId]['size'] !== $size)) {
                        throw new \DomainException(sprintf('WB chrtId=%s belongs to multiple card variants.', $chrtId));
                    }

                    $naturalKeys[$naturalKey] = $chrtId;
                    $barcodes = $this->barcodes($sizeData['skus'] ?? []);
                    foreach ($barcodes as $barcode) {
                        if (isset($barcodeOwners[$barcode]) && $barcodeOwners[$barcode] !== $chrtId) {
                            throw new \DomainException(sprintf('WB barcode=%s belongs to multiple chrtId values.', $barcode));
                        }
                        $barcodeOwners[$barcode] = $chrtId;
                    }
                    if (isset($variants[$chrtId])) {
                        $variants[$chrtId]['barcodes'] = array_values(array_unique([...$variants[$chrtId]['barcodes'], ...$barcodes]));
                        $variants[$chrtId]['isActive'] = $variants[$chrtId]['isActive'] || $isActive;
                        continue;
                    }

                    $variants[$chrtId] = [
                        'nmId' => $nmId,
                        'chrtId' => $chrtId,
                        'size' => $size,
                        'vendorCode' => trim((string) ($card['vendorCode'] ?? '')),
                        'brand' => trim((string) ($card['brand'] ?? '')),
                        'subjectName' => trim((string) ($card['subjectName'] ?? '')),
                        'title' => trim((string) ($card['title'] ?? '')),
                        'barcodes' => $barcodes,
                        'isActive' => $isActive,
                    ];
                }
            }
        }

        return array_values($variants);
    }

    private function positiveId(mixed $value, string $field): string
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if (false === $id || $id <= 0) {
            throw new \UnexpectedValueException(sprintf('WB Product Cards %s must be a positive integer.', $field));
        }

        return (string) $id;
    }

    /** @return list<string> */
    private function barcodes(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException('WB Product Cards skus must be a list.');
        }

        $barcodes = [];
        foreach ($value as $barcode) {
            if (!is_scalar($barcode)) {
                throw new \UnexpectedValueException('WB Product Cards barcode must be scalar.');
            }
            $barcode = trim((string) $barcode);
            if ('' !== $barcode) {
                $barcodes[] = $barcode;
            }
        }

        return array_values(array_unique($barcodes));
    }
}
