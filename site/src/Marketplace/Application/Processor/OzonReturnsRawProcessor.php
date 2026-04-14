<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Company\Entity\Company;
use App\Marketplace\Application\ProcessOzonReturnsAction;
use App\Marketplace\Application\Service\MarketplaceCostPriceResolver;
use App\Marketplace\Application\Service\OzonListingEnsureService;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Entity\MarketplaceReturn;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Repository\MarketplaceReturnRepository;
use App\Marketplace\Repository\MarketplaceSaleRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

final class OzonReturnsRawProcessor implements MarketplaceRawProcessorInterface
{
    /**
     * Последний rawDocId, для которого уже выполнена очистка legacy-записей
     * в рамках текущего жизненного цикла процессора. Нужен для идемпотентности,
     * когда один rawDocument разбит на несколько батчей.
     */
    private ?string $cleanedUpRawDocId = null;

    public function __construct(
        private readonly ProcessOzonReturnsAction $action,
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
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
        $rawDoc = $this->em->find(MarketplaceRawDocument::class, $rawDocId);
        if (!$rawDoc instanceof MarketplaceRawDocument) {
            throw new \RuntimeException('Raw document not found: ' . $rawDocId);
        }

        $this->connection->beginTransaction();

        try {
            $this->cleanupLegacyReturns($companyId, $rawDocId);

            $result = ($this->action)($companyId, $rawDocId);

            $this->connection->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
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
                $this->cleanupLegacyReturns($companyId, $rawDocId);
            }

            // existingMap строится ПОСЛЕ cleanup — иначе только что удалённые
            // external_id попадут в карту и insert-цикл ошибочно пропустит их
            // как «уже существующие», оставляя пустоты в marketplace_returns.
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
                if ($rawDocId !== null) {
                    $return->setRawDocumentId($rawDocId);
                }

                $this->em->persist($return);
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
     * `document_id IS NULL` — не трогаем уже закрытые в ОПиУ записи.
     */
    private function cleanupLegacyReturns(string $companyId, string $rawDocId): void
    {
        $rawDoc = $this->em->find(MarketplaceRawDocument::class, $rawDocId);
        if (!$rawDoc instanceof MarketplaceRawDocument) {
            return;
        }

        $periodFrom = $rawDoc->getPeriodFrom()->format('Y-m-d');
        $periodTo   = $rawDoc->getPeriodTo()->format('Y-m-d');

        $deletedByDoc = (int) $this->connection->executeStatement(
            'DELETE FROM marketplace_returns
             WHERE raw_document_id = :docId
               AND document_id IS NULL',
            ['docId' => $rawDocId],
        );

        $deletedLegacy = (int) $this->connection->executeStatement(
            'DELETE FROM marketplace_returns
             WHERE raw_document_id IS NULL
               AND document_id IS NULL
               AND company_id = :companyId
               AND marketplace = :marketplace
               AND return_date BETWEEN :periodFrom AND :periodTo',
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
                    '[Ozon] Очищено %d returns (по raw_document_id) + %d legacy returns за период %s—%s перед обработкой документа %s',
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
