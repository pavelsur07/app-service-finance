<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Company\Entity\Company;
use App\Marketplace\Application\ProcessOzonSalesAction;
use App\Marketplace\Application\Service\MarketplaceCostPriceResolver;
use App\Marketplace\Application\Service\OzonListingEnsureService;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Repository\MarketplaceSaleRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

final class OzonSalesRawProcessor implements MarketplaceRawProcessorInterface
{
    /**
     * Последний rawDocId, для которого уже выполнена очистка legacy-записей
     * в рамках текущего жизненного цикла процессора. Нужен для идемпотентности,
     * когда один rawDocument разбит на несколько батчей (>500 строк).
     */
    private ?string $cleanedUpRawDocId = null;
    /**
     * Per-run счётчики версий external_order_id.
     * Позволяют продолжать _vN между batch одного raw-документа внутри одного invocation.
     *
     * @var array<string, int>
     */
    private array $perRunVersionCounters = [];
    /**
     * Base keys, already existing in DB after cleanup, for which _vN generation is forbidden
     * within current run to avoid artificial duplicate bypass.
     *
     * @var array<string, true>
     */
    private array $perRunBlockedBaseKeys = [];

    public function __construct(
        private readonly ProcessOzonSalesAction $action,
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly MarketplaceSaleRepository $saleRepository,
        private readonly OzonListingEnsureService $listingEnsureService,
        private readonly MarketplaceCostPriceResolver $costPriceResolver,
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

    /**
     * @deprecated Use processBatch() instead. This method delegates to legacy ProcessOzonSalesAction which lacks storno classification and version suffix logic. No active callers in production.
     */
    public function process(string $companyId, string $rawDocId): int
    {
        $rawDoc = $this->em->find(MarketplaceRawDocument::class, $rawDocId);
        if (!$rawDoc instanceof MarketplaceRawDocument) {
            throw new \RuntimeException('Raw document not found: ' . $rawDocId);
        }

        $this->connection->beginTransaction();

        try {
            $this->cleanupLegacySales($companyId, $rawDocId);

            $result = ($this->action)($companyId, $rawDocId);

            $this->connection->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }


    public function resetPerRunState(): void
    {
        $this->cleanedUpRawDocId = null;
        $this->perRunVersionCounters = [];
        $this->perRunBlockedBaseKeys = [];
    }

    /**
     * @param array<int, array<string, mixed>> $rawRows
     */
    public function processBatch(
        string $companyId,
        MarketplaceType $marketplace,
        array $rawRows,
        ?string $rawDocId = null,
    ): void {
        if (empty($rawRows)) {
            return;
        }

        $company = $this->em->find(Company::class, $companyId);
        if (!$company instanceof Company) {
            throw new \RuntimeException('Company not found: ' . $companyId);
        }

        $salesData = array_filter($rawRows, static function (array $op): bool {
            if ((float) ($op['accruals_for_sale'] ?? 0) == 0) {
                return false;
            }

            $type = $op['type'] ?? '';
            $operationType = $op['operation_type'] ?? '';

            return $type === 'orders'
                || $operationType === 'OperationAgentStornoDeliveredToCustomer';
        });

        if (empty($salesData)) {
            return;
        }

        // Собираем SKU с именами для идемпотентного создания листингов
        $skusWithNames = [];
        foreach ($salesData as $op) {
            foreach ($op['items'] ?? [] as $item) {
                $sku = (string) ($item['sku'] ?? '');
                if ($sku !== '' && !isset($skusWithNames[$sku])) {
                    $skusWithNames[$sku] = $item['name'] ?? null;
                }
            }
        }

        // Идемпотентное создание/загрузка листингов (безопасно при параллельной обработке)
        $listingsCache = $this->listingEnsureService->ensureListings($company, $skusWithNames);

        // Очистка legacy-записей + вставка идут в одной транзакции.
        // Очистка запускается только один раз для каждого rawDocId
        // (daily pipeline может разбивать один raw документ на несколько батчей).
        $shouldCleanup  = $rawDocId !== null && $this->cleanedUpRawDocId !== $rawDocId;
        $useTransaction = $rawDocId !== null;

        if ($useTransaction) {
            $this->connection->beginTransaction();
        }

        try {
            if ($shouldCleanup) {
                $this->cleanupLegacySales($companyId, $rawDocId);
            }

            // existingMap строится ПОСЛЕ cleanup — иначе только что удалённые
            // external_id попадут в карту и insert-цикл ошибочно пропустит их
            // как «уже существующие», оставляя пустоты в marketplace_sales.

            // Сортируем по operation_date ASC для корректного назначения версий
            // при повторных продажах (sale → storno → re-sale по одному posting_number).
            usort($salesData, static fn (array $a, array $b): int => ($a['operation_date'] ?? '') <=> ($b['operation_date'] ?? ''));

            $baseKeysMap = [];
            foreach ($salesData as $i => $op) {
                $postingNumber = $op['posting']['posting_number'] ?? '';
                $baseKey = $postingNumber !== '' ? $postingNumber : (string) ($op['operation_id'] ?? '');
                if ((float) ($op['accruals_for_sale'] ?? 0) < 0) {
                    $baseKey .= '_storno';
                }
                $baseKeysMap[$i] = $baseKey;
            }

            $newBaseKeysForDbProbe = [];
            foreach (array_unique($baseKeysMap) as $baseKey) {
                if (isset($this->perRunVersionCounters[$baseKey]) || isset($this->perRunBlockedBaseKeys[$baseKey])) {
                    continue;
                }

                $newBaseKeysForDbProbe[] = $baseKey;
            }

            $existingBaseMap = $newBaseKeysForDbProbe === []
                ? []
                : $this->saleRepository->getExistingExternalIds($companyId, $newBaseKeysForDbProbe);
            foreach ($existingBaseMap as $existingBaseKey => $_) {
                $this->perRunBlockedBaseKeys[$existingBaseKey] = true;
            }

            // ВАЖНО: версии (_v2/_v3/...) генерируются только в рамках текущего run/invocation.
            // Не отталкиваемся от уже существующих строк БД, чтобы повторный импорт
            // не создавал искусственные новые external_order_id.
            $computedIds = [];
            foreach ($salesData as $i => $op) {
                $baseKey = $baseKeysMap[$i];
                if (isset($this->perRunBlockedBaseKeys[$baseKey])) {
                    $computedIds[$i] = $baseKey;
                    continue;
                }

                $this->perRunVersionCounters[$baseKey] = ($this->perRunVersionCounters[$baseKey] ?? 0) + 1;

                $computedIds[$i] = $this->perRunVersionCounters[$baseKey] > 1
                    ? $baseKey . '_v' . $this->perRunVersionCounters[$baseKey]
                    : $baseKey;
            }

            // Для idempotency сверяем только уже вычисленные ключи (base + batch versions).
            // TODO: если Ozon начнёт присылать устойчивые multi-line ключи, перейти на более
            // строгий natural key (например posting_number + SKU + operation_id) и убрать _vN.
            $existingMap = $this->saleRepository->getExistingExternalIds($companyId, array_values(array_unique($computedIds)));

            foreach ($salesData as $i => $op) {
                $externalId = $computedIds[$i];

                $accrual  = (float) ($op['accruals_for_sale'] ?? 0);
                $isStorno = $accrual < 0;

                if ($externalId === '' || isset($existingMap[$externalId])) {
                    continue;
                }

                $firstItem = ($op['items'] ?? [])[0] ?? null;
                $sku = $firstItem ? (string) ($firstItem['sku'] ?? '') : '';
                $listing = $listingsCache[$sku] ?? null;

                if (!$listing) {
                    $this->logger->warning('[Ozon] processBatch sales: listing not found', [
                        'external_id' => $externalId,
                        'sku'         => $sku,
                    ]);
                    continue;
                }

                $saleDate = new \DateTimeImmutable($op['operation_date']);

                $sale = new MarketplaceSale(
                    Uuid::uuid4()->toString(),
                    $company,
                    $listing,
                    MarketplaceType::OZON,
                );

                $sale->setExternalOrderId($externalId);
                $sale->setSaleDate($saleDate);
                $sale->setQuantity(count($op['items'] ?? []) ?: 1);
                $sale->setPricePerUnit((string) $accrual);
                $sale->setTotalRevenue((string) $accrual);
                // Storno: no goods shipped, cost_price must be null
                $sale->setCostPrice($isStorno ? null : $this->costPriceResolver->resolveForSale($listing, $saleDate));
                $sale->setRawData($op);
                if ($rawDocId !== null) {
                    $sale->setRawDocumentId($rawDocId);
                }

                $this->em->persist($sale);
                $existingMap[$externalId] = true;
            }

            $this->em->flush();

            if ($useTransaction) {
                $this->connection->commit();
            }

            // Marker выставляется ТОЛЬКО после успешного commit — иначе
            // откат транзакции оставил бы in-memory флаг установленным,
            // и retry в том же worker-процессе пропустил бы cleanup,
            // считая стороние legacy-строки валидными через existingMap.
            if ($shouldCleanup) {
                $this->cleanedUpRawDocId = $rawDocId;
            }
        } catch (\Throwable $e) {
            if ($useTransaction && $this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Удаляет записи текущего raw-документа (идемпотентный повтор обработки)
     * и legacy-записи без raw_document_id за тот же период.
     *
     * - `document_id IS NULL` — не трогаем уже закрытые в ОПиУ записи.
     * - Перед DELETE применяется guard по Company::financeLockBefore:
     *   reprocess запрещён, если период raw-документа пересекается с закрытым диапазоном.
     * - `price_per_unit > 0` в легаси-DELETE — отсекает storno-записи
     *   (negative accruals_for_sale, суффикс `_storno`, price_per_unit < 0),
     *   которых у старого потока `ProcessOzonSalesAction` не было.
     */
    private function cleanupLegacySales(string $companyId, string $rawDocId): void
    {
        $rawDoc = $this->em->find(MarketplaceRawDocument::class, $rawDocId);
        if (!$rawDoc instanceof MarketplaceRawDocument) {
            return;
        }

        $periodFrom = $rawDoc->getPeriodFrom()->format('Y-m-d');
        $periodTo   = $rawDoc->getPeriodTo()->format('Y-m-d');
        $company = $rawDoc->getCompany();
        $lockBefore = $company->getFinanceLockBefore();

        if ($lockBefore !== null && $rawDoc->getPeriodFrom() <= $lockBefore) {
            throw new \DomainException(sprintf(
                'Период raw-документа заблокирован для переобработки (financeLockBefore: %s, period: %s—%s).',
                $lockBefore->format('d.m.Y'),
                $periodFrom,
                $periodTo,
            ));
        }

        $deletedByDoc = $this->saleRepository->deleteByRawDocument(
            company: $company,
            marketplace: MarketplaceType::OZON,
            rawDocumentId: $rawDocId,
        );

        $deletedLegacy = (int) $this->connection->executeStatement(
            'DELETE FROM marketplace_sales
             WHERE raw_document_id IS NULL
               AND document_id IS NULL
               AND company_id = :companyId
               AND marketplace = :marketplace
               AND sale_date BETWEEN :periodFrom AND :periodTo
               AND price_per_unit > 0',
            [
                'companyId'   => $companyId,
                'marketplace' => MarketplaceType::OZON->value,
                'periodFrom'  => $periodFrom,
                'periodTo'    => $periodTo,
            ],
        );

        if ($deletedByDoc > 0 || $deletedLegacy > 0) {
            $this->logger->info(
                sprintf(
                    '[Ozon] Очищено %d sales (по raw_document_id) + %d legacy sales за период %s—%s перед обработкой документа %s',
                    $deletedByDoc,
                    $deletedLegacy,
                    $periodFrom,
                    $periodTo,
                    $rawDocId,
                ),
                [
                    'raw_doc_id'     => $rawDocId,
                    'company_id'     => $companyId,
                    'deleted_by_doc' => $deletedByDoc,
                    'deleted_legacy' => $deletedLegacy,
                    'period_from'    => $periodFrom,
                    'period_to'      => $periodTo,
                ],
            );
        }
    }
}
