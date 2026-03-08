<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Company\Entity\Company;
use App\Marketplace\Application\ProcessOzonCostsAction;
use App\Marketplace\Application\Service\MarketplaceCostCategoryResolver;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Infrastructure\Query\MarketplaceCostExistingExternalIdsQuery;
use App\Marketplace\Repository\MarketplaceListingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

final class OzonCostsRawProcessor implements MarketplaceRawProcessorInterface
{
    public function __construct(
        private readonly ProcessOzonCostsAction $action,
        private readonly EntityManagerInterface $em,
        private readonly MarketplaceListingRepository $listingRepository,
        private readonly MarketplaceCostExistingExternalIdsQuery $costExistingIdsQuery,
        private readonly MarketplaceCostCategoryResolver $categoryResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(string|StagingRecordType $type, MarketplaceType $marketplace, string $kind = ''): bool
    {
        if ($type instanceof StagingRecordType) {
            return $type === StagingRecordType::COST
                && $marketplace === MarketplaceType::OZON;
        }

        return $type === MarketplaceType::OZON->value && $kind === 'costs';
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

        $allSkus = [];
        foreach ($rawRows as $op) {
            foreach ($op['items'] ?? [] as $item) {
                $sku = (string) ($item['sku'] ?? '');
                if ($sku !== '') {
                    $allSkus[$sku] = true;
                }
            }
        }

        $listingsCache = $this->listingRepository->findListingsBySkusIndexed(
            $company,
            MarketplaceType::OZON,
            array_keys($allSkus),
        );

        $newListings = 0;
        foreach ($rawRows as $op) {
            $price = abs((float) ($op['amount'] ?? 0));
            foreach ($op['items'] ?? [] as $item) {
                $sku = (string) ($item['sku'] ?? '');
                if ($sku === '' || isset($listingsCache[$sku])) {
                    continue;
                }

                $listing = new MarketplaceListing(
                    Uuid::uuid4()->toString(),
                    $company,
                    null,
                    MarketplaceType::OZON,
                );
                $listing->setMarketplaceSku($sku);
                $listing->setPrice((string) $price);
                $listing->setName($item['name'] ?? null);
                $this->em->persist($listing);

                $listingsCache[$sku] = $listing;
                $newListings++;
            }
        }

        if ($newListings > 0) {
            $this->em->flush();
        }

        $this->categoryResolver->preload($company, MarketplaceType::OZON);

        // Собираем все cost entries
        $allEntries = [];
        foreach ($rawRows as $op) {
            $operationId = (string) ($op['operation_id'] ?? '');
            if ($operationId === '') {
                continue;
            }

            try {
                $operationDate = new \DateTimeImmutable($op['operation_date']);
            } catch (\Throwable) {
                continue;
            }

            $firstItem = ($op['items'] ?? [])[0] ?? null;
            $sku = $firstItem ? (string) ($firstItem['sku'] ?? '') : '';
            $listing = $listingsCache[$sku] ?? null;

            foreach ($this->extractCostEntries($op, $operationId, $operationDate) as $entry) {
                $allEntries[] = ['entry' => $entry, 'listing' => $listing];
            }
        }

        if (empty($allEntries)) {
            return;
        }

        $allExternalIds = array_unique(array_map(
            static fn (array $row): string => $row['entry']['external_id'],
            $allEntries,
        ));
        $existingMap = $this->costExistingIdsQuery->execute($companyId, $allExternalIds);

        foreach ($allEntries as $row) {
            $entry = $row['entry'];
            $externalId = $entry['external_id'];

            if (isset($existingMap[$externalId])) {
                continue;
            }

            $category = $this->categoryResolver->resolve(
                $company,
                MarketplaceType::OZON,
                $entry['category_code'],
                $entry['category_name'],
            );

            $cost = new MarketplaceCost(
                Uuid::uuid4()->toString(),
                $company,
                MarketplaceType::OZON,
                $category,
            );

            $cost->setExternalId($externalId);
            $cost->setCostDate($entry['cost_date']);
            $cost->setAmount($entry['amount']);
            $cost->setDescription($entry['description']);

            if ($row['listing']) {
                $cost->setListing($row['listing']);
            }

            $this->em->persist($cost);
            $existingMap[$externalId] = true;
        }

        $this->em->flush();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractCostEntries(array $op, string $operationId, \DateTimeImmutable $operationDate): array
    {
        $entries = [];

        $commission = abs((float) ($op['sale_commission'] ?? 0));
        if ($commission > 0) {
            $entries[] = [
                'external_id'   => $operationId . '_commission',
                'category_code' => 'ozon_sale_commission',
                'category_name' => 'Комиссия Ozon за продажу',
                'amount'        => (string) $commission,
                'cost_date'     => $operationDate,
                'description'   => 'Комиссия за продажу',
            ];
        }

        $delivery = abs((float) ($op['delivery_charge'] ?? 0));
        if ($delivery > 0) {
            $entries[] = [
                'external_id'   => $operationId . '_delivery',
                'category_code' => 'ozon_delivery',
                'category_name' => 'Доставка Ozon',
                'amount'        => (string) $delivery,
                'cost_date'     => $operationDate,
                'description'   => 'Стоимость доставки',
            ];
        }

        $returnDelivery = abs((float) ($op['return_delivery_charge'] ?? 0));
        if ($returnDelivery > 0) {
            $entries[] = [
                'external_id'   => $operationId . '_return_delivery',
                'category_code' => 'ozon_return_delivery',
                'category_name' => 'Обратная доставка Ozon',
                'amount'        => (string) $returnDelivery,
                'cost_date'     => $operationDate,
                'description'   => 'Обратная доставка',
            ];
        }

        foreach ($op['services'] ?? [] as $idx => $service) {
            $serviceAmount = abs((float) ($service['price'] ?? 0));
            if ($serviceAmount <= 0) {
                continue;
            }

            $serviceName = $service['name'] ?? 'Неизвестная услуга';
            $categoryCode = $this->resolveServiceCategoryCode($serviceName);

            $entries[] = [
                'external_id'   => $operationId . '_svc_' . $idx,
                'category_code' => $categoryCode,
                'category_name' => $this->resolveServiceCategoryName($categoryCode),
                'amount'        => (string) $serviceAmount,
                'cost_date'     => $operationDate,
                'description'   => $serviceName,
            ];
        }

        return $entries;
    }

    private function resolveServiceCategoryCode(string $serviceName): string
    {
        $lower = mb_strtolower($serviceName);

        return match (true) {
            str_contains($lower, 'логистик') || str_contains($lower, 'logistic')
            || str_contains($lower, 'магистраль') || str_contains($lower, 'last mile') => 'ozon_logistics',
            str_contains($lower, 'обработк') || str_contains($lower, 'processing')
            || str_contains($lower, 'сборк') => 'ozon_processing',
            str_contains($lower, 'хранени') || str_contains($lower, 'storage')
            || str_contains($lower, 'размещени') => 'ozon_storage',
            str_contains($lower, 'эквайринг') || str_contains($lower, 'acquiring')
            || str_contains($lower, 'приём платеж') => 'ozon_acquiring',
            str_contains($lower, 'продвижени') || str_contains($lower, 'реклам')
            || str_contains($lower, 'promotion') || str_contains($lower, 'трафик') => 'ozon_promotion',
            str_contains($lower, 'подписк') || str_contains($lower, 'premium')
            || str_contains($lower, 'subscription') => 'ozon_subscription',
            str_contains($lower, 'штраф') || str_contains($lower, 'penalty')
            || str_contains($lower, 'неустойк') => 'ozon_penalty',
            str_contains($lower, 'компенсац') || str_contains($lower, 'compensation')
            || str_contains($lower, 'возмещени') => 'ozon_compensation',
            default => 'ozon_other_service',
        };
    }

    private function resolveServiceCategoryName(string $categoryCode): string
    {
        return match ($categoryCode) {
            'ozon_sale_commission' => 'Комиссия Ozon за продажу',
            'ozon_delivery'        => 'Доставка Ozon',
            'ozon_return_delivery' => 'Обратная доставка Ozon',
            'ozon_logistics'       => 'Логистика Ozon',
            'ozon_processing'      => 'Обработка отправления Ozon',
            'ozon_storage'         => 'Хранение на складе Ozon',
            'ozon_acquiring'       => 'Эквайринг Ozon',
            'ozon_promotion'       => 'Продвижение / реклама Ozon',
            'ozon_subscription'    => 'Подписка Ozon',
            'ozon_penalty'         => 'Штрафы Ozon',
            'ozon_compensation'    => 'Компенсации Ozon',
            default                => 'Прочие услуги Ozon',
        };
    }
}
