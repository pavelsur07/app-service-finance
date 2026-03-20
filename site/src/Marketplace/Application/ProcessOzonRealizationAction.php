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
            $returnCommission   = $row['return_commission'] ?? null;

            $quantity         = (int)   ($deliveryCommission['quantity'] ?? 0);
            $pricePerInstance = (float) ($deliveryCommission['price_per_instance'] ?? 0);

            if ($sku === '') {
                $skipped++;
                continue;
            }

            // Строка «только возврат»: delivery_commission = null, return_commission заполнен.
            // Пример: товар возвращён в периоде без продажи в этом же периоде.
            // Создаём запись с total_amount = 0 и заполненным return_amount,
            // чтобы return_realization попал в ОПиУ при закрытии месяца.
            if ($quantity <= 0 || $pricePerInstance <= 0) {
                if ($returnCommission === null) {
                    $skipped++;
                    continue;
                }

                $returnPrice = (float) ($returnCommission['price_per_instance'] ?? 0);
                $returnQty   = (int)   ($returnCommission['quantity'] ?? 0);

                if ($returnPrice <= 0 || $returnQty <= 0) {
                    $skipped++;
                    continue;
                }

                // Создаём строку с нулевой продажей — только для учёта возврата
                $realization = new MarketplaceOzonRealization(
                    Uuid::uuid4()->toString(),
                    $companyId,
                    $rawDoc,
                    $sku,
                    '0.00',
                    $returnQty,
                    $periodFrom,
                    $periodTo,
                );
                $realization->setOfferId($offerId);
                $realization->setName($name);
                $realization->setListing($listingsCache[$sku] ?? null);
                $realization->setReturnCommission($returnPrice, $returnQty);

                $this->em->persist($realization);
                $created++;
                $counter++;

                if ($counter % $batchSize === 0) {
                    $this->em->flush();
                    $this->em->clear();
                    $rawDoc = $this->em->find(MarketplaceRawDocument::class, $rawDocId);
                }

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
     * Переобработка через удаление и пересоздание строк.
     *
     * Предыдущие подходы (индексирование по SKU, UPDATE по sku+quantity) не работали
     * из-за дублей: один SKU встречается много раз с одинаковым quantity но разным
     * price_per_instance — нет уникального ключа кроме UUID строки.
     *
     * Стратегия:
     *   1. Сохранить pl_document_id существующих строк (чтобы не потерять связь с PLDocument)
     *   2. Удалить все строки периода для данного raw_document_id
     *   3. Создать строки заново из JSON с правильными полями
     *   4. Восстановить pl_document_id по SKU
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

        // 1. Сохраняем pl_document_id по SKU перед удалением
        //    (нужно восстановить связь с PLDocument после пересоздания)
        $plDocumentIds = $connection->fetchAllKeyValue(
            'SELECT sku, pl_document_id
             FROM marketplace_ozon_realizations
             WHERE company_id     = :companyId
               AND raw_document_id = :rawDocId
               AND pl_document_id IS NOT NULL',
            ['companyId' => $companyId, 'rawDocId' => $rawDocId],
        );

        // 2. Удаляем все строки этого raw-документа
        $deleted = $connection->executeStatement(
            'DELETE FROM marketplace_ozon_realizations
             WHERE company_id     = :companyId
               AND raw_document_id = :rawDocId',
            ['companyId' => $companyId, 'rawDocId' => $rawDocId],
        );

        $this->logger->info('[OzonRealization] Reprocess: deleted existing rows', [
            'raw_doc_id' => $rawDocId,
            'deleted'    => $deleted,
        ]);

        // 3. Пересоздаём через process() — создаёт строки с правильными полями
        $company = $this->em->find(Company::class, $companyId);
        $result  = $this->process($companyId, $rawDoc, $rows, $periodFrom, $periodTo);

        // 4. Восстанавливаем pl_document_id для закрытых строк
        if (!empty($plDocumentIds)) {
            foreach ($plDocumentIds as $sku => $plDocumentId) {
                if ($plDocumentId === null) {
                    continue;
                }

                $connection->executeStatement(
                    'UPDATE marketplace_ozon_realizations
                     SET pl_document_id = :plDocumentId
                     WHERE company_id     = :companyId
                       AND raw_document_id = :rawDocId
                       AND sku            = :sku',
                    [
                        'plDocumentId' => $plDocumentId,
                        'companyId'    => $companyId,
                        'rawDocId'     => $rawDocId,
                        'sku'          => (string) $sku,
                    ],
                );
            }

            $this->logger->info('[OzonRealization] Reprocess: restored pl_document_id', [
                'raw_doc_id' => $rawDocId,
                'restored'   => count($plDocumentIds),
            ]);
        }

        $this->logger->info('[OzonRealization] Reprocess completed', [
            'raw_doc_id' => $rawDocId,
            'created'    => $result['created'],
            'skipped'    => $result['skipped'],
        ]);

        return ['created' => 0, 'updated' => $result['created'], 'skipped' => $result['skipped']];
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
