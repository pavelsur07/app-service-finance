<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Company\Entity\Company;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Application\ProcessOzonCostsAction;
use App\Marketplace\Application\Service\MarketplaceCostCategoryResolver;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Infrastructure\Query\MarketplaceCostExistingExternalIdsQuery;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Marketplace\Service\CostCalculator\CostCalculatorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

final class OzonCostsRawProcessor implements MarketplaceRawProcessorInterface
{
    /** @var iterable<CostCalculatorInterface> */
    private iterable $costCalculators;

    public function __construct(
        private readonly ProcessOzonCostsAction $action,
        private readonly EntityManagerInterface $em,
        private readonly MarketplaceListingRepository $listingRepository,
        private readonly MarketplaceCostExistingExternalIdsQuery $costExistingIdsQuery,
        private readonly MarketplaceCostCategoryResolver $categoryResolver,
        private readonly LoggerInterface $logger,
        iterable $costCalculators,
    ) {
        $this->costCalculators = $costCalculators;
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

        $costsData = array_filter($rawRows, static fn (array $item): bool => ($item['type'] ?? null) === 'costs');
        if (empty($costsData)) {
            return;
        }

        $allSkus = [];
        foreach ($costsData as $op) {
            foreach ($op['items'] ?? [] as $item) {
                $sku = (string) ($item['sku'] ?? '');
                if ($sku !== '') {
                    $allSkus[$sku] = true;
                }
            }
        }

        /** @var array<string, MarketplaceListing> $listingsCache */
        $listingsCache = $this->listingRepository->findListingsBySkusIndexed(
            $company,
            MarketplaceType::OZON,
            array_keys($allSkus),
        );

        foreach ($costsData as $op) {
            foreach ($op['items'] ?? [] as $item) {
                $sku = (string) ($item['sku'] ?? '');
                if ($sku === '' || isset($listingsCache[$sku])) {
                    continue;
                }

                $price = abs((float) ($op['amount'] ?? 0));
                $listing = new MarketplaceListing(Uuid::uuid4()->toString(), $company, null, MarketplaceType::OZON);
                $listing->setMarketplaceSku($sku);
                $listing->setPrice((string) $price);
                $listing->setName($item['name'] ?? null);

                $this->em->persist($listing);
                $listingsCache[$sku] = $listing;
            }
        }

        $this->categoryResolver->preload($company, MarketplaceType::OZON);

        $pendingCosts = [];
        $externalIds = [];

        foreach ($costsData as $op) {
            try {
                $operationId = (string) ($op['operation_id'] ?? '');
                if ($operationId === '') {
                    continue;
                }

                $operationDate = new \DateTimeImmutable((string) ($op['operation_date'] ?? 'now'));

                $listing = null;
                $firstItem = ($op['items'] ?? [])[0] ?? null;
                if (is_array($firstItem)) {
                    $sku = (string) ($firstItem['sku'] ?? '');
                    $listing = $listingsCache[$sku] ?? null;
                }

                foreach ($this->extractOzonCostEntries($op, $operationId, $operationDate) as $entry) {
                    $externalId = (string) ($entry['external_id'] ?? '');
                    if ($externalId === '') {
                        continue;
                    }

                    $pendingCosts[] = [
                        'external_id' => $externalId,
                        'entry' => $entry,
                        'listing' => $listing,
                    ];
                    $externalIds[] = $externalId;
                }
            } catch (\Throwable $exception) {
                $this->logger->error('[Ozon] Failed to process cost row in batch mode', [
                    'company_id' => $companyId,
                    'operation_id' => $op['operation_id'] ?? null,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $existingExternalIds = $this->costExistingIdsQuery->execute($companyId, $externalIds);

        foreach ($pendingCosts as $pendingCost) {
            $externalId = $pendingCost['external_id'];
            if (isset($existingExternalIds[$externalId])) {
                continue;
            }

            $entry = $pendingCost['entry'];
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

            if ($pendingCost['listing'] instanceof MarketplaceListing) {
                $cost->setListing($pendingCost['listing']);
            }

            $this->em->persist($cost);
            $existingExternalIds[$externalId] = true;
        }

        $this->em->flush();
    }

    private function extractOzonCostEntries(array $op, string $operationId, \DateTimeImmutable $operationDate): array
    {
        $entries = [];

        $commission = abs((float) ($op['sale_commission'] ?? 0));
        if ($commission > 0) {
            $entries[] = [
                'external_id' => $operationId . '_commission',
                'category_code' => 'ozon_sale_commission',
                'category_name' => 'Комиссия Ozon за продажу',
                'amount' => (string) $commission,
                'cost_date' => $operationDate,
                'description' => 'Комиссия за продажу',
            ];
        }

        $delivery = abs((float) ($op['delivery_charge'] ?? 0));
        if ($delivery > 0) {
            $entries[] = [
                'external_id' => $operationId . '_delivery',
                'category_code' => 'ozon_delivery',
                'category_name' => 'Доставка Ozon',
                'amount' => (string) $delivery,
                'cost_date' => $operationDate,
                'description' => 'Стоимость доставки',
            ];
        }

        $returnDelivery = abs((float) ($op['return_delivery_charge'] ?? 0));
        if ($returnDelivery > 0) {
            $entries[] = [
                'external_id' => $operationId . '_return_delivery',
                'category_code' => 'ozon_return_delivery',
                'category_name' => 'Обратная доставка Ozon',
                'amount' => (string) $returnDelivery,
                'cost_date' => $operationDate,
                'description' => 'Обратная доставка',
            ];
        }

        $services = $op['services'] ?? [];
        foreach ($services as $idx => $service) {
            $serviceAmount = abs((float) ($service['price'] ?? 0));
            if ($serviceAmount <= 0) {
                continue;
            }

            $serviceName = $service['name'] ?? 'Неизвестная услуга';
            $categoryCode = $this->resolveOzonServiceCategoryCode($serviceName);

            $entries[] = [
                'external_id' => $operationId . '_svc_' . $idx,
                'category_code' => $categoryCode,
                'category_name' => $this->resolveOzonServiceCategoryName($categoryCode),
                'amount' => (string) $serviceAmount,
                'cost_date' => $operationDate,
                'description' => $serviceName,
            ];
        }

        return $entries;
    }

    private function resolveOzonServiceCategoryCode(string $serviceName): string
    {
        $lower = mb_strtolower($serviceName);

        if (str_contains($lower, 'логистик') || str_contains($lower, 'logistic')
            || str_contains($lower, 'магистраль') || str_contains($lower, 'last mile')) {
            return 'ozon_logistics';
        }

        if (str_contains($lower, 'обработк') || str_contains($lower, 'processing')
            || str_contains($lower, 'сборк')) {
            return 'ozon_processing';
        }

        if (str_contains($lower, 'хранени') || str_contains($lower, 'storage')
            || str_contains($lower, 'размещени')) {
            return 'ozon_storage';
        }

        if (str_contains($lower, 'эквайринг') || str_contains($lower, 'acquiring')
            || str_contains($lower, 'приём платеж')) {
            return 'ozon_acquiring';
        }

        if (str_contains($lower, 'продвижени') || str_contains($lower, 'реклам')
            || str_contains($lower, 'promotion') || str_contains($lower, 'трафик')) {
            return 'ozon_promotion';
        }

        if (str_contains($lower, 'подписк') || str_contains($lower, 'premium')
            || str_contains($lower, 'subscription')) {
            return 'ozon_subscription';
        }

        if (str_contains($lower, 'штраф') || str_contains($lower, 'penalty')
            || str_contains($lower, 'неустойк')) {
            return 'ozon_penalty';
        }

        if (str_contains($lower, 'компенсац') || str_contains($lower, 'compensation')
            || str_contains($lower, 'возмещени')) {
            return 'ozon_compensation';
        }

        return 'ozon_other_service';
    }

    private function resolveOzonServiceCategoryName(string $categoryCode): string
    {
        return match ($categoryCode) {
            'ozon_sale_commission' => 'Комиссия Ozon за продажу',
            'ozon_delivery' => 'Доставка Ozon',
            'ozon_return_delivery' => 'Обратная доставка Ozon',
            'ozon_logistics' => 'Логистика Ozon',
            'ozon_processing' => 'Обработка отправления Ozon',
            'ozon_storage' => 'Хранение на складе Ozon',
            'ozon_acquiring' => 'Эквайринг Ozon',
            'ozon_promotion' => 'Продвижение / реклама Ozon',
            'ozon_subscription' => 'Подписка Ozon',
            'ozon_penalty' => 'Штрафы Ozon',
            'ozon_compensation' => 'Компенсации Ozon',
            default => 'Прочие услуги Ozon',
        };
    }
}
