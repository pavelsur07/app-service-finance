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
 *   "item": {
 *     "sku": 1851658436,
 *     "offer_id": "241550",
 *     "name": "..."
 *   },
 *   "seller_price_per_instance": 1256,     ← цена продавца = выручка
 *   "delivery_commission": {
 *     "quantity": 1,                        ← количество
 *     ...
 *   }
 * }
 *
 * Выручка = seller_price_per_instance × delivery_commission.quantity.
 * Сохраняем в marketplace_ozon_realizations (денормализованная таблица).
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
     * @return array{created: int, skipped: int}
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

            return ['created' => 0, 'skipped' => 0];
        }

        // Период из заголовка реализации
        $periodFrom = new \DateTimeImmutable($header['start_date'] ?? date('Y-m-01'));
        $periodTo   = new \DateTimeImmutable($header['stop_date'] ?? date('Y-m-t'));

        // Проверяем — уже обработан ли этот период
        if ($this->realizationRepository->existsForPeriod(
            $companyId,
            $periodFrom->format('Y-m-d'),
            $periodTo->format('Y-m-d'),
        )) {
            $this->logger->info('[OzonRealization] Already processed for period', [
                'period_from' => $periodFrom->format('Y-m-d'),
                'period_to'   => $periodTo->format('Y-m-d'),
            ]);

            return ['created' => 0, 'skipped' => count($rows)];
        }

        // Собираем все SKU для batch-загрузки листингов
        $allSkus = [];
        foreach ($rows as $row) {
            $sku = (string) ($row['item']['sku'] ?? '');
            if ($sku !== '') {
                $allSkus[$sku] = true;
            }
        }

        $listingsCache = $this->listingRepository->findListingsBySkusIndexed(
            $company,
            MarketplaceType::OZON,
            array_keys($allSkus),
        );

        $created   = 0;
        $skipped   = 0;
        $batchSize = 250;
        $counter   = 0;

        foreach ($rows as $row) {
            $sku      = (string) ($row['item']['sku'] ?? '');
            $offerId  = (string) ($row['item']['offer_id'] ?? '') ?: null;
            $name     = (string) ($row['item']['name'] ?? '') ?: null;
            $price    = (float) ($row['seller_price_per_instance'] ?? 0);
            $quantity = (int) ($row['delivery_commission']['quantity'] ?? 0);

            if ($sku === '' || $price <= 0 || $quantity <= 0) {
                $skipped++;
                continue;
            }

            $realization = new MarketplaceOzonRealization(
                Uuid::uuid4()->toString(),
                $companyId,
                $rawDoc,
                $sku,
                number_format($price, 2, '.', ''),
                $quantity,
                $periodFrom,
                $periodTo,
            );

            $realization->setOfferId($offerId);
            $realization->setName($name);
            $realization->setListing($listingsCache[$sku] ?? null);

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

        // Обновляем статистику raw документа
        $rawDoc = $this->rawDocumentRepository->find($rawDocId);
        if ($rawDoc !== null) {
            $rawDoc->setRecordsCreated($created);
            $rawDoc->setRecordsSkipped($skipped);
            $this->em->flush();
        }

        $this->logger->info('[OzonRealization] Completed', [
            'raw_doc_id' => $rawDocId,
            'created'    => $created,
            'skipped'    => $skipped,
        ]);

        return ['created' => $created, 'skipped' => $skipped];
    }
}
