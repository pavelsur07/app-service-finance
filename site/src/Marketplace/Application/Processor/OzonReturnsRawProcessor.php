<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Company\Entity\Company;
use App\Marketplace\Application\ProcessOzonReturnsAction;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceReturn;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
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
    ) {
    }

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

        // $rawRows уже содержит только ClientReturnAgentOperation (классифицировано как RETURN)
        // Фильтрация по type=returns здесь не нужна — доверяем классификатору

        // Собираем все SKU
        $allSkus = [];
        foreach ($rawRows as $op) {
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
        foreach ($rawRows as $op) {
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

        // Дедупликация — ключ: posting_number ?: operation_id
        $allExternalIds = array_values(array_map(
            static function (array $op): string {
                $postingNumber = $op['posting']['posting_number'] ?? '';
                return $postingNumber !== '' ? $postingNumber : (string) ($op['operation_id'] ?? '');
            },
            $rawRows,
        ));
        $existingMap = $this->returnRepository->getExistingExternalIds($companyId, $allExternalIds);

        foreach ($rawRows as $op) {
            $postingNumber = $op['posting']['posting_number'] ?? '';
            $externalId = $postingNumber !== '' ? $postingNumber : (string) ($op['operation_id'] ?? '');

            if ($externalId === '' || isset($existingMap[$externalId])) {
                continue;
            }

            $firstItem = ($op['items'] ?? [])[0] ?? null;
            $sku = $firstItem ? (string) ($firstItem['sku'] ?? '') : '';
            $listingId = $listingsIdCache[$sku] ?? null;

            if (!$listingId) {
                $this->logger->warning('[Ozon] processBatch returns: listing not found', [
                    'external_id'    => $externalId,
                    'operation_type' => $op['operation_type'] ?? '',
                    'sku'            => $sku,
                ]);
                continue;
            }

            $listing = $this->em->getReference(MarketplaceListing::class, $listingId);

            // Сумма возврата: accruals_for_sale (отрицательный при возврате) ?: amount
            $refundAmount = abs((float) ($op['accruals_for_sale'] ?? 0));
            if ($refundAmount <= 0) {
                $refundAmount = abs((float) ($op['amount'] ?? 0));
            }

            $return = new MarketplaceReturn(
                Uuid::uuid4()->toString(),
                $company,
                $listing,
                MarketplaceType::OZON,
            );

            $return->setExternalReturnId($externalId);
            $return->setReturnDate(new \DateTimeImmutable($op['operation_date']));
            $return->setQuantity(count($op['items'] ?? []) ?: 1);
            $return->setRefundAmount((string) $refundAmount);
            $return->setReturnReason($op['operation_type_name'] ?? null);
            $return->setRawData($op);

            $this->em->persist($return);
            $existingMap[$externalId] = true;
        }

        $this->em->flush();
    }
}
