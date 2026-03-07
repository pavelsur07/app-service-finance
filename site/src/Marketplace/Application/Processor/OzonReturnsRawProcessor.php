<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceReturn;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Application\ProcessOzonReturnsAction;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Marketplace\Repository\MarketplaceReturnRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

final class OzonReturnsRawProcessor implements MarketplaceRawProcessorInterface
{
    public function __construct(
        private readonly ProcessOzonReturnsAction $action,
        private readonly EntityManagerInterface $em,
        private readonly MarketplaceReturnRepository $returnRepository,
        private readonly MarketplaceListingRepository $listingRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function supports(string|StagingRecordType $type, MarketplaceType $marketplace, string $kind = ''): bool
    {
        if ($type instanceof StagingRecordType) {
            return $type === StagingRecordType::RETURN
            && $marketplace === MarketplaceType::OZON;
        }

        return $type === MarketplaceType::OZON->value && $kind === 'returns';
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

        $returnsData = array_filter($rawRows, static fn (array $item): bool => ($item['type'] ?? null) === 'returns');
        if (empty($returnsData)) {
            return;
        }

        $allSkus = array_values(array_unique(array_filter(array_map(
            static fn (array $item): string => (string) ($item['sku'] ?? ''),
            $returnsData,
        ))));

        /** @var array<string, MarketplaceListing> $listingsCache */
        $listingsCache = $this->listingRepository->findListingsBySkusIndexed($company, MarketplaceType::OZON, $allSkus);

        foreach ($returnsData as $item) {
            $sku = (string) ($item['sku'] ?? '');
            if ($sku === '' || isset($listingsCache[$sku])) {
                continue;
            }

            $listing = new MarketplaceListing(Uuid::uuid4()->toString(), $company, null, MarketplaceType::OZON);
            $listing->setMarketplaceSku($sku);
            $listing->setPrice((string) abs((float) ($item['amount'] ?? 0)));
            $listing->setName($item['items'][0]['name'] ?? null);

            $this->em->persist($listing);
            $listingsCache[$sku] = $listing;
        }

        $this->em->flush();

        $allOperationIds = array_values(array_filter(array_map(
            static fn (array $item): string => (string) ($item['operation_id'] ?? ''),
            $returnsData,
        )));
        $existingMap = $this->returnRepository->getExistingExternalIds($companyId, $allOperationIds);

        foreach ($returnsData as $item) {
            $operationId = (string) ($item['operation_id'] ?? '');
            if ($operationId === '' || isset($existingMap[$operationId])) {
                continue;
            }

            $sku = (string) ($item['sku'] ?? '');
            $listing = $sku !== '' ? ($listingsCache[$sku] ?? null) : null;
            if (!$listing instanceof MarketplaceListing) {
                $this->logger->warning('[Ozon] processBatch: listing not found', ['sku' => $sku]);

                continue;
            }

            $return = new MarketplaceReturn(
                Uuid::uuid4()->toString(),
                $company,
                $listing,
                MarketplaceType::OZON,
            );

            $return->setExternalReturnId((string) $item['operation_id']);
            $return->setReturnDate(new \DateTimeImmutable((string) $item['operation_date']));
            $return->setQuantity(1);
            $return->setRefundAmount((string) abs((float) ($item['amount'] ?? 0)));
            $return->setReturnReason($item['operation_type_name'] ?? null);

            $this->em->persist($return);
            $existingMap[$operationId] = true;
        }

        $this->em->flush();
    }
}
