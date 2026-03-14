<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Marketplace\Repository\MarketplaceSaleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Обрабатывает realization-документ Ozon (POST /v2/finance/realization).
 *
 * Что делает:
 *   Для каждой строки из result.rows[] находит соответствующую запись
 *   в marketplace_sales по posting_number + sku и обновляет totalRevenue
 *   значением sale.amount ("Реализовано на сумму, руб.").
 *
 * Структура строки realization:
 * {
 *   "item_code": "220280923",    ← SKU
 *   "item_name": "...",
 *   "barcode": "...",
 *   "sale": {
 *     "amount": 2900.95,         ← "Реализовано на сумму" → totalRevenue
 *     "discount_amount": 29.01,  ← выплаты по механикам лояльности
 *     "qty": 1,
 *     "price": 2900.95           ← цена реализации единицы
 *   },
 *   "posting_number": "...",     ← номер отправления (если есть в строке)
 *   ...
 * }
 *
 * Важно: realization — позаказный отчёт, одна строка = один SKU в одном заказе.
 * Матчим по (posting_number + sku) → находим MarketplaceSale → обновляем totalRevenue.
 */
final class ProcessOzonRealizationAction
{
    public function __construct(
        private readonly MarketplaceRawDocumentRepository $rawDocumentRepository,
        private readonly MarketplaceSaleRepository $saleRepository,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{updated: int, skipped: int, not_found: int}
     */
    public function __invoke(string $companyId, string $rawDocId): array
    {
        $rawDoc = $this->rawDocumentRepository->find($rawDocId);

        if ($rawDoc === null) {
            throw new \RuntimeException(sprintf('Raw document not found: %s', $rawDocId));
        }

        if ($rawDoc->getMarketplace() !== MarketplaceType::OZON) {
            throw new \InvalidArgumentException('ProcessOzonRealizationAction supports only Ozon documents.');
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

        if (empty($rows)) {
            $this->logger->info('ProcessOzonRealization: no rows in document', [
                'raw_doc_id' => $rawDocId,
            ]);

            return ['updated' => 0, 'skipped' => 0, 'not_found' => 0];
        }

        $updated  = 0;
        $skipped  = 0;
        $notFound = 0;

        foreach ($rows as $row) {
            $postingNumber = $row['posting_number'] ?? null;
            $sku           = (string) ($row['item_code'] ?? '');
            $saleAmount    = $row['sale']['amount'] ?? null;

            // Пропускаем строки без суммы или без posting_number
            if ($saleAmount === null || $saleAmount <= 0) {
                $skipped++;
                continue;
            }

            if (empty($postingNumber) || empty($sku)) {
                $this->logger->warning('ProcessOzonRealization: missing posting_number or sku', [
                    'row' => $row,
                ]);
                $skipped++;
                continue;
            }

            // Ищем продажу по posting_number + sku
            $sale = $this->saleRepository->findByMarketplaceOrderAndSku(
                $company,
                MarketplaceType::OZON,
                $postingNumber,
                $sku,
            );

            if ($sale === null) {
                $this->logger->debug('ProcessOzonRealization: sale not found', [
                    'posting_number' => $postingNumber,
                    'sku'            => $sku,
                ]);
                $notFound++;
                continue;
            }

            $newRevenue = number_format((float) $saleAmount, 2, '.', '');

            // Обновляем только если значение изменилось
            if ($sale->getTotalRevenue() === $newRevenue) {
                $skipped++;
                continue;
            }

            $sale->setTotalRevenue($newRevenue);
            $updated++;

            // Батчевый flush каждые 100 записей
            if ($updated % 100 === 0) {
                $this->em->flush();
                $this->em->clear();

                // После clear нужно снова найти company (она была detached)
                $company = $this->em->find(Company::class, $companyId);
            }
        }

        $this->em->flush();

        $this->logger->info('ProcessOzonRealization: completed', [
            'raw_doc_id' => $rawDocId,
            'updated'    => $updated,
            'skipped'    => $skipped,
            'not_found'  => $notFound,
        ]);

        // Обновляем статистику документа
        $rawDoc = $this->rawDocumentRepository->find($rawDocId);
        if ($rawDoc !== null) {
            $rawDoc->setRecordsCreated($updated);
            $rawDoc->setRecordsSkipped($skipped + $notFound);
            $this->em->flush();
        }

        return [
            'updated'   => $updated,
            'skipped'   => $skipped,
            'not_found' => $notFound,
        ];
    }
}
