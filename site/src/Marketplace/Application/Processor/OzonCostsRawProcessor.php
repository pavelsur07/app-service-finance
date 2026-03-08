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

        // Собираем все SKU из всех операций
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

        // Прогрев категорий
        $this->categoryResolver->preload($company, MarketplaceType::OZON);

        // Генерируем все cost entries из всех операций (логика из OzonAdapter::fetchCosts)
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
            $listingId = $listingsIdCache[$sku] ?? null;

            foreach ($this->extractCostEntries($op, $operationId, $operationDate) as $entry) {
                $allEntries[] = ['entry' => $entry, 'listingId' => $listingId];
            }
        }

        if (empty($allEntries)) {
            return;
        }

        // Дедупликация
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

            if ($row['listingId']) {
                $listingRef = $this->em->getReference(MarketplaceListing::class, $row['listingId']);
                $cost->setListing($listingRef);
            }

            $this->em->persist($cost);
            $existingMap[$externalId] = true;
        }

        $this->em->flush();
    }

    /**
     * Извлекает затраты из операции — логика идентична OzonAdapter::fetchCosts.
     * Обрабатывает ВСЕ типы операций: orders, returns, services, other, compensation.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractCostEntries(array $op, string $operationId, \DateTimeImmutable $operationDate): array
    {
        $entries = [];

        // Комиссия за продажу
        $saleCommission = abs((float) ($op['sale_commission'] ?? 0));
        if ($saleCommission > 0) {
            $entries[] = [
                'external_id'   => $operationId . '_commission',
                'category_code' => 'ozon_sale_commission',
                'category_name' => 'Комиссия Ozon за продажу',
                'amount'        => (string) $saleCommission,
                'cost_date'     => $operationDate,
                'description'   => 'Комиссия за продажу Ozon',
            ];
        }

        // Стоимость доставки
        $deliveryCharge = abs((float) ($op['delivery_charge'] ?? 0));
        if ($deliveryCharge > 0) {
            $entries[] = [
                'external_id'   => $operationId . '_delivery',
                'category_code' => 'ozon_delivery',
                'category_name' => 'Доставка Ozon',
                'amount'        => (string) $deliveryCharge,
                'cost_date'     => $operationDate,
                'description'   => 'Доставка Ozon',
            ];
        }

        // Обратная доставка
        $returnDeliveryCharge = abs((float) ($op['return_delivery_charge'] ?? 0));
        if ($returnDeliveryCharge > 0) {
            $entries[] = [
                'external_id'   => $operationId . '_return_delivery',
                'category_code' => 'ozon_return_delivery',
                'category_name' => 'Обратная доставка Ozon',
                'amount'        => (string) $returnDeliveryCharge,
                'cost_date'     => $operationDate,
                'description'   => 'Обратная доставка Ozon',
            ];
        }

        // Сервисы внутри операции (логистика, хранение и т.д.)
        foreach ($op['services'] ?? [] as $idx => $service) {
            $servicePrice = abs((float) ($service['price'] ?? 0));
            if ($servicePrice <= 0) {
                continue;
            }

            $serviceName = $service['name'] ?? 'Услуга Ozon';
            $categoryCode = $this->resolveServiceCategoryCode($serviceName);

            $entries[] = [
                'external_id'   => $operationId . '_svc_' . $idx,
                'category_code' => $categoryCode,
                'category_name' => $this->resolveCategoryName($categoryCode),
                'amount'        => (string) $servicePrice,
                'cost_date'     => $operationDate,
                'description'   => $serviceName,
            ];
        }

        // Прямые затраты для type=services, other, compensation
        // (операции где amount — сама затрата, и она не отражена в полях выше)
        $opType = $op['type'] ?? '';
        if (in_array($opType, ['services', 'other', 'compensation'], true)) {
            $amount = abs((float) ($op['amount'] ?? 0));
            // Только если нет services[] (иначе уже учтено выше)
            if ($amount > 0 && empty($op['services'])) {
                $operationType = $op['operation_type'] ?? '';
                $operationTypeName = $op['operation_type_name'] ?? 'Прочие услуги Ozon';
                $categoryCode = $this->resolveOperationTypeCategoryCode($operationType, $opType);

                $entries[] = [
                    'external_id'   => $operationId . '_main',
                    'category_code' => $categoryCode,
                    'category_name' => $this->resolveCategoryName($categoryCode, $operationTypeName),
                    'amount'        => (string) $amount,
                    'cost_date'     => $operationDate,
                    'description'   => $operationTypeName,
                ];
            }
        }

        return $entries;
    }

    /**
     * Маппинг названий услуг Ozon → коды категорий (из OzonAdapter).
     */
    private function resolveServiceCategoryCode(string $serviceName): string
    {
        $lower = mb_strtolower($serviceName);

        if (str_contains($lower, 'логистик') || str_contains($lower, 'магистраль')
            || str_contains($lower, 'last mile') || str_contains($lower, 'last_mile')
            || str_contains($lower, 'logistic') || str_contains($lower, 'crossdocking')) {
            return 'ozon_logistics';
        }

        if (str_contains($lower, 'сборка') || str_contains($lower, 'обработк')
            || str_contains($lower, 'processing') || str_contains($lower, 'supply')) {
            return 'ozon_processing';
        }

        if (str_contains($lower, 'хранени') || str_contains($lower, 'storage')
            || str_contains($lower, 'размещени')) {
            return 'ozon_storage';
        }

        if (str_contains($lower, 'эквайринг') || str_contains($lower, 'acquiring')
            || str_contains($lower, 'redistribution')) {
            return 'ozon_acquiring';
        }

        if (str_contains($lower, 'продвижени') || str_contains($lower, 'реклам')
            || str_contains($lower, 'promotion') || str_contains($lower, 'costperclick')) {
            return 'ozon_promotion';
        }

        if (str_contains($lower, 'подписк') || str_contains($lower, 'premium')
            || str_contains($lower, 'subscription') || str_contains($lower, 'earlypayment')) {
            return 'ozon_subscription';
        }

        if (str_contains($lower, 'штраф') || str_contains($lower, 'penalty')) {
            return 'ozon_penalty';
        }

        if (str_contains($lower, 'компенсац') || str_contains($lower, 'возмещ')
            || str_contains($lower, 'compensation')) {
            return 'ozon_compensation';
        }

        return 'ozon_other_service';
    }

    private function resolveOperationTypeCategoryCode(string $operationType, string $opType): string
    {
        if ($opType === 'compensation') {
            return 'ozon_compensation';
        }

        return $this->resolveServiceCategoryCode($operationType);
    }

    private function resolveCategoryName(string $categoryCode, string $fallback = ''): string
    {
        return match ($categoryCode) {
            'ozon_sale_commission'  => 'Комиссия Ozon за продажу',
            'ozon_delivery'         => 'Доставка Ozon',
            'ozon_return_delivery'  => 'Обратная доставка Ozon',
            'ozon_logistics'        => 'Логистика Ozon',
            'ozon_processing'       => 'Обработка отправления Ozon',
            'ozon_storage'          => 'Хранение на складе Ozon',
            'ozon_acquiring'        => 'Эквайринг Ozon',
            'ozon_promotion'        => 'Продвижение / реклама Ozon',
            'ozon_subscription'     => 'Подписка Ozon',
            'ozon_penalty'          => 'Штрафы Ozon',
            'ozon_compensation'     => 'Компенсации Ozon',
            default                 => $fallback ?: 'Прочие услуги Ozon',
        };
    }
}
