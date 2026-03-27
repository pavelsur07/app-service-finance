<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Company\Entity\Company;
use App\Marketplace\Application\ProcessOzonReturnsAction;
use App\Marketplace\Application\Service\MarketplaceCostPriceResolver;
use App\Marketplace\Application\Service\OzonListingEnsureService;
use App\Marketplace\Entity\MarketplaceReturn;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Repository\MarketplaceReturnRepository;
use App\Marketplace\Repository\MarketplaceSaleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

final class OzonReturnsRawProcessor implements MarketplaceRawProcessorInterface
{
    public function __construct(
        private readonly ProcessOzonReturnsAction $action,
        private readonly EntityManagerInterface $em,
        private readonly MarketplaceReturnRepository $returnRepository,
        private readonly OzonListingEnsureService $listingEnsureService,
        private readonly MarketplaceSaleRepository $saleRepository,
        private readonly MarketplaceCostPriceResolver $costPriceResolver,
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

        // Собираем SKU с именами для идемпотентного создания листингов
        $skusWithNames = [];
        foreach ($rawRows as $op) {
            foreach ($op['items'] ?? [] as $item) {
                $sku = (string) ($item['sku'] ?? '');
                if ($sku !== '' && !isset($skusWithNames[$sku])) {
                    $skusWithNames[$sku] = $item['name'] ?? null;
                }
            }
        }

        // Идемпотентное создание/загрузка листингов (безопасно при параллельной обработке)
        $listingsCache = $this->listingEnsureService->ensureListings($company, $skusWithNames);

        // Дедупликация
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
            $listing = $listingsCache[$sku] ?? null;

            if (!$listing) {
                $this->logger->warning('[Ozon] processBatch returns: listing not found', [
                    'external_id'    => $externalId,
                    'operation_type' => $op['operation_type'] ?? '',
                    'sku'            => $sku,
                ]);
                continue;
            }

            // Ищем связанную продажу по posting_number для получения costPrice
            $sale = null;
            if ($postingNumber !== '') {
                $sale = $this->saleRepository->findByMarketplaceOrder(
                    $company,
                    MarketplaceType::OZON,
                    $postingNumber,
                );
            }

            $refundAmount = abs((float) ($op['accruals_for_sale'] ?? 0));
            if ($refundAmount <= 0) {
                $refundAmount = abs((float) ($op['amount'] ?? 0));
            }

            $returnDate = new \DateTimeImmutable($op['operation_date']);

            $return = new MarketplaceReturn(
                Uuid::uuid4()->toString(),
                $company,
                $listing,
                MarketplaceType::OZON,
            );

            $return->setExternalReturnId($externalId);
            $return->setReturnDate($returnDate);
            $return->setQuantity(count($op['items'] ?? []) ?: 1);
            $return->setRefundAmount((string) $refundAmount);
            $return->setReturnReason($op['operation_type_name'] ?? null);
            $return->setCostPrice($this->costPriceResolver->resolveForReturn($listing, $sale, $op));
            $return->setRawData($op);

            $this->em->persist($return);
            $existingMap[$externalId] = true;
        }

        $this->em->flush();
    }
}
