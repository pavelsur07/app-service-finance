<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Company\Entity\Company;
use App\Marketplace\Application\Service\MarketplaceCostCategoryResolver;
use App\Marketplace\Application\Service\MappingErrorLogger;
use App\Marketplace\Application\Service\OzonListingEnsureService;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Infrastructure\Query\MarketplaceCostExistingExternalIdsQuery;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Обрабатывает затраты Ozon из sales_report через процессорный pipeline.
 *
 * Вызывается из ProcessMarketplaceRawDocumentAction → MarketplaceRawProcessorRegistry
 * при нажатии кнопки «Затраты» или при переобработке периода.
 *
 * Логика категоризации:
 * - Категоризация через OzonServiceCategoryMap (единый словарь маппинга)
 * - Fallback fuzzy + логирование warning для неизвестных service names
 * - Нулевые маркеры (price=0) пропускаются
 * - Multi-SKU эквайринг делится поровну по items
 */
final class OzonCostsRawProcessor implements MarketplaceRawProcessorInterface
{

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly OzonListingEnsureService $listingEnsureService,
        private readonly MarketplaceCostExistingExternalIdsQuery $costExistingIdsQuery,
        private readonly MarketplaceCostCategoryResolver $categoryResolver,
        private readonly MappingErrorLogger $mappingErrorLogger,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Контекст текущего батча для логирования ошибок маппинга */
    private string $currentCompanyId = '';
    private int $currentYear = 0;
    private int $currentMonth = 0;

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
        // Удаляем старые затраты по raw_document_id (только незакрытые в ОПиУ)
        $deleted = $this->connection->executeStatement(
            'DELETE FROM marketplace_costs WHERE raw_document_id = :rawDocId AND document_id IS NULL',
            ['rawDocId' => $rawDocId],
        );

        if ($deleted > 0) {
            $this->logger->info('[Ozon] Deleted existing costs for reprocessing', [
                'raw_doc_id' => $rawDocId,
                'deleted'    => $deleted,
            ]);
        }

        // Читаем все операции из raw документа включая type=orders.
        // processBatch() получает только COST-bucket (type=services/returns/other),
        // но комиссия и логистика из type=orders тоже являются затратами.
        $rawDoc = $this->em->find(MarketplaceRawDocument::class, $rawDocId);
        if (!$rawDoc instanceof MarketplaceRawDocument) {
            return 0;
        }

        $rows = $rawDoc->getRawData();
        if (isset($rows['result']['operations']) && is_array($rows['result']['operations'])) {
            $rows = $rows['result']['operations'];
        }

        // Передаём ВСЕ строки — processBatch сам разберётся что обрабатывать
        $this->processBatch($companyId, MarketplaceType::OZON, $rows, $rawDocId);

        return 0;
    }

    /**
     * @param array<int, array<string, mixed>> $rawRows
     */
    public function processBatch(string $companyId, MarketplaceType $marketplace, array $rawRows, ?string $rawDocId = null): void
    {
        if (empty($rawRows)) {
            return;
        }

        $company = $this->em->find(Company::class, $companyId);
        if (!$company instanceof Company) {
            throw new \RuntimeException('Company not found: ' . $companyId);
        }

        // Определяем период из первой строки для логирования ошибок маппинга
        $this->currentCompanyId = $companyId;
        $firstDate = null;
        foreach ($rawRows as $op) {
            if (!empty($op['operation_date'])) {
                try {
                    $firstDate = new \DateTimeImmutable($op['operation_date']);
                } catch (\Throwable) {}
                break;
            }
        }
        $this->currentYear  = $firstDate ? (int) $firstDate->format('Y') : (int) date('Y');
        $this->currentMonth = $firstDate ? (int) $firstDate->format('n') : (int) date('n');

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
        $listingsIdCache = [];
        foreach ($this->listingEnsureService->ensureListings($company, $skusWithNames) as $sku => $listing) {
            $listingsIdCache[$sku] = $listing->getId();
        }

        $this->categoryResolver->preload($company, MarketplaceType::OZON);
        // Сброс кеша после возможного em->clear() между батчами
        $this->categoryResolver->resetCache();

        // Генерируем cost entries
        $allEntries = [];
        foreach ($rawRows as $op) {
            $operationId = (string) ($op['operation_id'] ?? '');
            if ($operationId === '') {
                continue;
            }

            // ClientReturnAgentOperation — финансовый возврат покупателю.
            // Сам возврат обрабатывается в OzonReturnsRawProcessor.
            // Но sale_commission > 0 = возврат комиссии продавцу — это уменьшение затрат.
            if (($op['operation_type'] ?? '') === 'ClientReturnAgentOperation') {
                $returnCommission = (float) ($op['sale_commission'] ?? 0);
                if ($returnCommission > 0) {
                    $operationId   = (string) ($op['operation_id'] ?? '');
                    $operationDate = new \DateTimeImmutable($op['operation_date']);
                    // Отрицательная затрата — уменьшает комиссию
                    $allEntries[] = [
                        'entry' => [
                            'external_id'   => $operationId . '_commission_return',
                            'category_code' => 'ozon_sale_commission',
                            'category_name' => 'Комиссия Ozon за продажу',
                            'amount'        => (string) (-$returnCommission),
                            'cost_date'     => $operationDate,
                            'description'   => 'Возврат комиссии Ozon',
                            '_item_idx'     => 0,
                        ],
                        'listingId' => null,
                    ];
                }
                continue;
            }

            try {
                $operationDate = new \DateTimeImmutable($op['operation_date']);
            } catch (\Throwable) {
                continue;
            }

            foreach ($this->extractCostEntries($op, $operationId, $operationDate) as $entry) {
                $itemIdx   = $entry['_item_idx'] ?? 0;
                $items     = $op['items'] ?? [];
                $sku       = (string) ($items[$itemIdx]['sku'] ?? '');
                $listingId = $sku !== '' ? ($listingsIdCache[$sku] ?? null) : null;

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
            $entry      = $row['entry'];
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
            if ($rawDocId !== null) {
                $cost->setRawDocumentId($rawDocId);
            }
            $cost->setCostDate($entry['cost_date']);
            $cost->setAmount($entry['amount']);
            $cost->setDescription($entry['description']);

            if ($row['listingId']) {
                $cost->setListing($this->em->getReference(MarketplaceListing::class, $row['listingId']));
            }

            $this->em->persist($cost);
            $existingMap[$externalId] = true;
        }

        $this->em->flush();
        $this->mappingErrorLogger->resetBatch();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractCostEntries(array $op, string $operationId, \DateTimeImmutable $operationDate): array
    {
        $entries = [];

        // 1. Комиссия
        // sale_commission < 0 — обычная комиссия (затрата, берём abs → положительная)
        // sale_commission > 0 — возврат/корректировка комиссии продавцу (уменьшение затрат → отрицательная)
        $rawCommission = (float) ($op['sale_commission'] ?? 0);
        if (abs($rawCommission) > 0.001) {
            $commissionAmount = $rawCommission < 0
                ? abs($rawCommission)
                : -$rawCommission;

            $entries[] = [
                'external_id'   => $operationId . '_commission',
                'category_code' => 'ozon_sale_commission',
                'category_name' => 'Комиссия Ozon за продажу',
                'amount'        => (string) $commissionAmount,
                'cost_date'     => $operationDate,
                'description'   => $rawCommission > 0
                    ? 'Возврат комиссии Ozon (корректировка)'
                    : 'Комиссия за продажу Ozon',
                '_item_idx'     => 0,
            ];
        }

        // 2. Доставка
        $delivery = abs((float) ($op['delivery_charge'] ?? 0));
        if ($delivery > 0) {
            $entries[] = [
                'external_id'   => $operationId . '_delivery',
                'category_code' => 'ozon_delivery',
                'category_name' => 'Доставка Ozon',
                'amount'        => (string) $delivery,
                'cost_date'     => $operationDate,
                'description'   => 'Доставка Ozon',
                '_item_idx'     => 0,
            ];
        }

        // 3. Обратная доставка
        $returnDelivery = abs((float) ($op['return_delivery_charge'] ?? 0));
        if ($returnDelivery > 0) {
            $entries[] = [
                'external_id'   => $operationId . '_return_delivery',
                'category_code' => 'ozon_return_delivery',
                'category_name' => 'Обратная доставка Ozon',
                'amount'        => (string) $returnDelivery,
                'cost_date'     => $operationDate,
                'description'   => 'Обратная доставка Ozon',
                '_item_idx'     => 0,
            ];
        }

        // 4. services[]
        $services  = $op['services'] ?? [];
        $items     = $op['items'] ?? [];
        $itemCount = max(1, count($items));

        foreach ($services as $svcIdx => $service) {
            $servicePrice  = (float) ($service['price'] ?? 0);
            $serviceAmount = abs($servicePrice);

            if ($serviceAmount <= 0.001) {
                continue;
            }

            $serviceName = $service['name'] ?? '';

            // Нулевые маркеры по таблице
            if (OzonServiceCategoryMap::isZeroMarker($serviceName)) {
                continue;
            }

            // Положительный price = возврат затрат (напр. возврат эквайринга при возврате покупателя)
            // Сохраняем отрицательный знак чтобы уменьшать затраты
            if ($servicePrice > 0) {
                $serviceAmount = -$serviceAmount;
            }

            $categoryCode = $this->resolveServiceCategoryCode($serviceName);

            if ($itemCount === 1) {
                $entries[] = [
                    'external_id'   => $operationId . '_svc_' . $svcIdx,
                    'category_code' => $categoryCode,
                    'category_name' => $this->resolveCategoryName($categoryCode),
                    'amount'        => (string) $serviceAmount,
                    'cost_date'     => $operationDate,
                    'description'   => $serviceName,
                    '_item_idx'     => 0,
                ];
            } else {
                // Multi-SKU: делим поровну
                $perItem     = round($serviceAmount / $itemCount, 2);
                $distributed = 0.0;

                foreach ($items as $itemIdx => $item) {
                    $isLast  = ($itemIdx === $itemCount - 1);
                    $amount  = $isLast ? round($serviceAmount - $distributed, 2) : $perItem;
                    $distributed += $perItem;

                    $entries[] = [
                        'external_id'   => $operationId . '_svc_' . $svcIdx . '_item_' . $itemIdx,
                        'category_code' => $categoryCode,
                        'category_name' => $this->resolveCategoryName($categoryCode),
                        'amount'        => (string) $amount,
                        'cost_date'     => $operationDate,
                        'description'   => $serviceName,
                        '_item_idx'     => $itemIdx,
                    ];
                }
            }
        }

        // 5. Если services[] пустой — затраты в op['amount'] напрямую
        // Применимо к: реклама, хранение, кросс-докинг, поставка, досрочная выплата и т.д.
        if (empty($services)) {
            $opAmount = abs((float) ($op['amount'] ?? 0));
            $opType   = $op['type'] ?? '';

            // Пропускаем продажи и компенсации (не затраты)
            // type=compensation включает как компенсации от Ozon (доход) так и декомпенсации (расход),
            // но они учитываются нетто на стороне Ozon — в ОПиУ не включаем.
            if ($opAmount > 0.001 && !in_array($opType, ['orders', 'compensation'], true)) {
                $operationType = $op['operation_type'] ?? '';
                $categoryCode  = $this->resolveServiceCategoryCode($operationType);

                $entries[] = [
                    'external_id'   => $operationId . '_main',
                    'category_code' => $categoryCode,
                    'category_name' => $this->resolveCategoryName($categoryCode),
                    'amount'        => (string) $opAmount,
                    'cost_date'     => $operationDate,
                    'description'   => $op['operation_type_name'] ?? $operationType,
                    '_item_idx'     => 0,
                ];
            }
        }

        return $entries;
    }

    private function resolveServiceCategoryCode(string $serviceName, ?array $rawOp = null): string
    {
        $code = OzonServiceCategoryMap::resolve($serviceName, $this->logger);

        if ($code === null) {
            // Неизвестный service_name — логируем для мониторинга в админке
            $this->mappingErrorLogger->log(
                companyId:     $this->currentCompanyId,
                marketplace:   MarketplaceType::OZON->value,
                year:          $this->currentYear,
                month:         $this->currentMonth,
                serviceName:   $serviceName,
                operationType: $rawOp['operation_type'] ?? '',
                amount:        abs((float) ($rawOp['amount'] ?? 0)),
                sampleRaw:     $rawOp,
            );

            return 'ozon_other_service';
        }

        return $code;
    }

    private function resolveCategoryName(string $categoryCode): string
    {
        return OzonServiceCategoryMap::getCategoryName($categoryCode);
    }


}
