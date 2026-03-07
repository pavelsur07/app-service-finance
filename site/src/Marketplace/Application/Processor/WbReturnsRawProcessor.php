<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Company\Entity\Company;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Application\ProcessWbReturnsAction;
use App\Marketplace\Application\Service\WbListingResolverService;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceReturn;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Marketplace\Repository\MarketplaceReturnRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

final class WbReturnsRawProcessor implements MarketplaceRawProcessorInterface
{
    public function __construct(
        private readonly ProcessWbReturnsAction $action,
        private readonly EntityManagerInterface $em,
        private readonly MarketplaceReturnRepository $returnRepository,
        private readonly MarketplaceListingRepository $listingRepository,
        private readonly WbListingResolverService $listingResolver,
        private readonly LoggerInterface $logger,
    ) {}

    public function supports(string|StagingRecordType $type, MarketplaceType $marketplace, string $kind = ''): bool
    {
        if ($type instanceof StagingRecordType) {
            return $type === StagingRecordType::RETURN
            && $marketplace === MarketplaceType::WILDBERRIES;
        }

        return $type === MarketplaceType::WILDBERRIES->value && $kind === 'returns';
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

        $returnsData = array_filter($rawRows, static function (array $item): bool {
            $docType = $item['doc_type_name'] ?? '';

            return in_array($docType, ['Возврат', 'возврат', 'Return'], true)
                && (float) ($item['retail_price'] ?? 0) > 0;
        });

        if (empty($returnsData)) {
            return;
        }

        $allNmIds = array_values(array_unique(array_column($returnsData, 'nm_id')));

        /** @var array<string, MarketplaceListing> $listingsCache */
        $listingsCache = $this->listingRepository->findListingsByNmIdsIndexed(
            $company,
            MarketplaceType::WILDBERRIES,
            $allNmIds,
        );

        $newListings = 0;
        foreach ($returnsData as $item) {
            $nmId = (string) ($item['nm_id'] ?? '');
            $tsName = $item['ts_name'] ?? null;
            $size = trim((string) $tsName) !== '' ? trim((string) $tsName) : 'UNKNOWN';
            $cacheKey = $nmId . '_' . $size;

            if (isset($listingsCache[$cacheKey])) {
                continue;
            }

            $listing = $this->listingResolver->resolve($company, $nmId, $tsName, [
                'sa_name'      => (string) ($item['sa_name'] ?? ''),
                'brand_name'   => (string) ($item['brand_name'] ?? ''),
                'subject_name' => (string) ($item['subject_name'] ?? ''),
                'retail_price' => (string) ($item['retail_price'] ?? '0'),
            ]);

            $listingsCache[$cacheKey] = $listing;
            $newListings++;
        }

        if ($newListings > 0) {
            $this->em->flush();
        }

        $allSrids = array_column($returnsData, 'srid');
        $existingMap = $this->returnRepository->getExistingExternalIds($companyId, $allSrids);

        foreach ($returnsData as $item) {
            $srid = (string) ($item['srid'] ?? '');
            if ($srid === '' || isset($existingMap[$srid])) {
                continue;
            }

            $nmId = (string) ($item['nm_id'] ?? '');
            $tsName = $item['ts_name'] ?? null;
            $size = trim((string) $tsName) !== '' ? trim((string) $tsName) : 'UNKNOWN';
            $cacheKey = $nmId . '_' . $size;
            $listing = $listingsCache[$cacheKey] ?? null;

            if (!$listing) {
                $this->logger->warning('[WB] processBatch: listing not found', [
                    'nm_id' => $nmId,
                ]);

                continue;
            }

            $return = new MarketplaceReturn(
                Uuid::uuid4()->toString(),
                $company,
                $listing,
                MarketplaceType::WILDBERRIES,
            );

            $return->setExternalReturnId($srid);
            $return->setReturnDate(new \DateTimeImmutable((string) $item['rr_dt']));
            $return->setQuantity(abs((int) $item['quantity']));
            $return->setRefundAmount((string) $item['retail_price']);
            $return->setReturnReason((string) ($item['supplier_oper_name'] ?? ''));

            $this->em->persist($return);
            $existingMap[$srid] = true;
        }

        $this->em->flush();
    }
}
