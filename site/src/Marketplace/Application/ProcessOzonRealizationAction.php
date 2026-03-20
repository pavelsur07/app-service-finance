<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceOzonRealization;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Marketplace\Repository\MarketplaceOzonRealizationRepository;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Обрабатывает realization-документ Ozon (POST /v2/finance/realization).
 *
 * Структура строки реализации v2:
 * {
 *   "item": { "sku": 220280923, "offer_id": "...", "name": "..." },
 *   "seller_price_per_instance": 2430,       ← цена продавца БЕЗ СПП (не используется)
 *   "delivery_commission": {
 *     "price_per_instance": 1594.67,          ← цена покупателя с учётом СПП — используем
 *     "quantity": 1,
 *     ...
 *   },
 *   "return_commission": {                    ← nullable; заполнен если был возврат
 *     "price_per_instance": 1594.67,          ← цена возврата с учётом СПП
 *     "quantity": 1,
 *     ...
 *   }
 * }
 *
 * Выручка   = delivery_commission.price_per_instance × delivery_commission.quantity
 * Возврат   = return_commission.price_per_instance × return_commission.quantity
 *
 * Переобработка (повторный вызов для уже обработанного периода):
 *   Существующие строки обновляются — заполняются поля return_commission
 *   которые отсутствовали до добавления фичи «Возврат с СПП».
 *   Строки с pl_document_id (уже закрытые) также обновляются —
 *   данные нужны для корректного закрытия следующих периодов.
 */
final class ProcessOzonRealizationAction
{
    public function __construct(
        private readonly MarketplaceRawDocumentRepository      $rawDocumentRepository,
        private readonly MarketplaceOzonRealizationRepository  $realizationRepository,
        private readonly MarketplaceListingRepository          $listingRepository,
        private readonly EntityManagerInterface                $em,
        private readonly LoggerInterface                       $logger,
    ) {
    }

    /**
     * @return array{created: int, updated: int, skipped: int}
     */
    public function __invoke(string $companyId, string $rawDocId): array
    {
        $rawDoc = $this->rawDocumentRepository->find($rawDocId);

        if ($rawDoc === null) {
            throw new \RuntimeException(sprintf('Raw document not found: %s', $rawDocId));
        }

        if ($rawDoc->getMarketplace() !== MarketplaceType::OZON) {
            throw new \InvalidArgumentException('ProcessOzonRealizationAction supports only Ozon.');
        }

        if ($rawDoc->getDocumentType() !== 'realization') {
            throw new \InvalidArgumentException(sprintf(
                'Expected documentType "realization", got "%s".',
                $rawDoc->getDocumentType(),
            ));
        }

        $company = $this->em->find(Company::class, $companyId);
        if (!$company instanceof Company) {
            throw new \RuntimeException('Company not found: ' . $companyId);
        }

        $rawData = $rawDoc->getRawData();
        $rows    = $rawData['result']['rows'] ?? [];
        $header  = $rawData['result']['header'] ?? [];

        if (empty($rows)) {
            $this->logger->info('[OzonRealization] No rows in document', ['raw_doc_id' => $rawDocId]);

            return ['created' => 0, 'updated' => 0, 'skipped' => 0];
        }

        // Период из заголовка реализации
        $periodFrom = new \DateTimeImmutable($header['start_date'] ?? date('Y-m-01'));
        $periodTo   = new \DateTimeImmutable($header['stop_date'] ?? date('Y-m-t'));

        // Проверяем режим: первичная обработка или переобработка (повторный запуск)
        $isReprocess = $this->realizationRepository->existsForPeriod(
            $companyId,
            $periodFrom->format('Y-m-d'),
            $periodTo->format('Y-m-d'),
        );

        if ($isReprocess) {
            $this->logger->info('[OzonRealization] Reprocessing existing period — updating return_commission fields', [
                'period_from' => $periodFrom->format('Y-m-d'),
                'period_to'   => $periodTo->format('Y-m-d'),
            ]);

            return $this->reprocess($companyId, $rawDoc, $rows, $periodFrom, $periodTo);
        }

        return $this->process($companyId, $rawDoc, $rows, $periodFrom, $periodTo);
    }

    // -------------------------------------------------------------------------
    // Первичная обработка
    // -------------------------------------------------------------------------

    /**
     * @return array{created: int, updated: int, skipped: int}
     */
    private function process(
        string $companyId,
        MarketplaceRawDocument $rawDoc,
        array $rows,
        \DateTimeImmutable $periodFrom,
        \DateTimeImmutable $periodTo,
    ): array {
        $allSkus = [];
        foreach ($rows as $row) {
            $sku = (string) ($row['item']['sku'] ?? '');
            if ($sku !== '') {
                $allSkus[$sku] = true;
            }
        }

        $listingsCache = $this->listingRepository->findListingsBySkusIndexed(
            $this->em->find(Company::class, $companyId),
            MarketplaceType::OZON,
            array_keys($allSkus),
        );

        $created   = 0;
        $skipped   = 0;
        $batchSize = 250;
        $counter   = 0;
        $rawDocId  = $rawDoc->getId();

        foreach ($rows as $row) {
            $sku      = (string) ($row['item']['sku'] ?? '');
            $offerId  = (string) ($row['item']['offer_id'] ?? '') ?: null;
            $name     = (string) ($row['item']['name'] ?? '') ?: null;

            $deliveryCommission = $row['delivery_commission'] ?? null;
            $quantity           = (int) ($deliveryCommission['quantity'] ?? 0);

            // Цена покупателя с учётом СПП
            $pricePerInstance   = (float) ($deliveryCommission['price_per_instance'] ?? 0);

            // Пропускаем строки без данных продажи (возврат без продажи в периоде)
            // Такие строки: delivery_commission = null, только return_commission заполнен
            if ($sku === '' || $quantity <= 0 || $pricePerInstance <= 0) {
                $skipped++;
                continue;
            }

            $realization = new MarketplaceOzonRealization(
                Uuid::uuid4()->toString(),
                $companyId,
                $rawDoc,
                $sku,
                number_format($pricePerInstance, 2, '.', ''),
                $quantity,
                $periodFrom,
                $periodTo,
            );

            $realization->setOfferId($offerId);
            $realization->setName($name);
            $realization->setListing($listingsCache[$sku] ?? null);

            // Возврат
            $returnCommission = $row['return_commission'] ?? null;
            if ($returnCommission !== null) {
                $returnPrice = (float) ($returnCommission['price_per_instance'] ?? 0);
                $returnQty   = (int)   ($returnCommission['quantity'] ?? 0);
                $realization->setReturnCommission($returnPrice, $returnQty);
            }

            $this->em->persist($realization);

            $created++;
            $counter++;

            if ($counter % $batchSize === 0) {
                $this->em->flush();
                $this->em->clear();

                $company = $this->em->find(Company::class, $companyId);
                $rawDoc  = $this->em->find(MarketplaceRawDocument::class, $rawDocId);

                foreach ($listingsCache as $k => $listing) {
                    $listingsCache[$k] = $this->em->getReference(
                        \App\Marketplace\Entity\MarketplaceListing::class,
                        $listing->getId(),
                    );
                }

                gc_collect_cycles();
            }
        }

        $this->em->flush();

        $this->updateRawDocStats($rawDocId, $created, $skipped);

        $this->logger->info('[OzonRealization] Completed', [
            'raw_doc_id' => $rawDocId,
            'created'    => $created,
            'skipped'    => $skipped,
        ]);

        return ['created' => $created, 'updated' => 0, 'skipped' => $skipped];
    }

    // -------------------------------------------------------------------------
    // Переобработка: обновляем поля return_commission в существующих строках
    // -------------------------------------------------------------------------

    /**
     * Переобработка нужна для двух сценариев:
     *   1. Исторические данные: строки созданы старым кодом который брал
     *      seller_price_per_instance вместо delivery_commission.price_per_instance.
     *      Пересчитываем pricePerInstance и totalAmount + заполняем return_commission.
     *   2. Пользователь нажал «Применить выручку» повторно.
     *
     * Важно: строки с pl_document_id (уже закрытые) тоже обновляем —
     * при следующем переоткрытии/закрытии данные будут корректными.
     *
     * @return array{created: int, updated: int, skipped: int}
     */
    /**
     * Переобработка через bulk DBAL UPDATE.
     *
     * Проблема старого подхода (findByPeriodIndexedBySku):
     *   один SKU встречается много раз с разными quantity и price_per_instance,
     *   индексирование по SKU брало только первую строку — остальные пропускались.
     *
     * Новый подход: UPDATE по (company_id, raw_document_id, sku, quantity) —
     * это уникально идентифицирует строку внутри одного документа реализации.
     *
     * @return array{created: int, updated: int, skipped: int}
     */
    private function reprocess(
        string $companyId,
        MarketplaceRawDocument $rawDoc,
        array $rows,
        \DateTimeImmutable $periodFrom,
        \DateTimeImmutable $periodTo,
    ): array {
        $rawDocId   = $rawDoc->getId();
        $connection = $this->em->getConnection();
        $updated    = 0;
        $skipped    = 0;

        foreach ($rows as $row) {
            $sku                = (string) ($row['item']['sku'] ?? '');
            $deliveryCommission = $row['delivery_commission'] ?? null;
            $returnCommission   = $row['return_commission'] ?? null;

            if ($sku === '') {
                $skipped++;
                continue;
            }

            $setParts = [];
            $params   = [
                'companyId' => $companyId,
                'rawDocId'  => $rawDocId,
                'sku'       => $sku,
            ];

            // 1. Обновляем delivery_commission — исправляем seller_price → price_per_instance
            if ($deliveryCommission !== null) {
                $pricePerInstance = (float) ($deliveryCommission['price_per_instance'] ?? 0);
                $quantity         = (int)   ($deliveryCommission['quantity'] ?? 0);

                if ($pricePerInstance > 0 && $quantity > 0) {
                    $price       = number_format($pricePerInstance, 2, '.', '');
                    $totalAmount = bcmul($price, (string) $quantity, 2);

                    $setParts[]              = 'seller_price_per_instance = :pricePerInstance';
                    $setParts[]              = 'total_amount = :totalAmount';
                    $params['pricePerInstance'] = $price;
                    $params['totalAmount']       = $totalAmount;
                    $params['quantity']          = $quantity;
                }
            }

            // 2. Обновляем return_commission
            if ($returnCommission !== null) {
                $returnPrice = (float) ($returnCommission['price_per_instance'] ?? 0);
                $returnQty   = (int)   ($returnCommission['quantity'] ?? 0);

                if ($returnPrice > 0 && $returnQty > 0) {
                    $rPrice      = number_format($returnPrice, 2, '.', '');
                    $returnTotal = bcmul($rPrice, (string) $returnQty, 2);

                    $setParts[]                       = 'return_price_per_instance = :returnPrice';
                    $setParts[]                       = 'return_quantity = :returnQty';
                    $setParts[]                       = 'return_amount = :returnAmount';
                    $params['returnPrice']             = $rPrice;
                    $params['returnQty']               = $returnQty;
                    $params['returnAmount']            = $returnTotal;
                }
            }

            if (empty($setParts)) {
                $skipped++;
                continue;
            }

            // WHERE по (company_id, raw_document_id, sku, quantity) —
            // уникально идентифицирует строку внутри документа реализации
            $whereQuantity = isset($params['quantity'])
                ? 'AND quantity = :quantity'
                : '';

            $affected = $connection->executeStatement(
                sprintf(
                    'UPDATE marketplace_ozon_realizations SET %s
                     WHERE company_id    = :companyId
                       AND raw_document_id = :rawDocId
                       AND sku           = :sku
                       %s',
                    implode(', ', $setParts),
                    $whereQuantity,
                ),
                $params,
            );

            if ($affected > 0) {
                $updated += $affected;
            } else {
                $skipped++;
                $this->logger->warning('[OzonRealization] Reprocess: no rows matched', [
                    'sku'      => $sku,
                    'rawDocId' => $rawDocId,
                ]);
            }
        }

        $this->logger->info('[OzonRealization] Reprocess completed', [
            'raw_doc_id' => $rawDocId,
            'updated'    => $updated,
            'skipped'    => $skipped,
        ]);

        return ['created' => 0, 'updated' => $updated, 'skipped' => $skipped];
    }

    private function updateRawDocStats(string $rawDocId, int $created, int $skipped): void
    {
        $rawDoc = $this->rawDocumentRepository->find($rawDocId);
        if ($rawDoc !== null) {
            $rawDoc->setRecordsCreated($created);
            $rawDoc->setRecordsSkipped($skipped);
            $this->em->flush();
        }
    }
}
