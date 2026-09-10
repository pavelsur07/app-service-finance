<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Company\Entity\Company;
use App\Marketplace\Application\Service\MarketplaceCostPriceResolver;
use App\Marketplace\Application\Service\OzonListingEnsureService;
use App\Marketplace\Entity\MarketplaceReturn;
use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Repository\MarketplaceReturnRepository;
use App\Marketplace\Repository\MarketplaceSaleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Возвраты из начислений Ozon `/v1/finance/accrual/by-day`.
 *
 * Возврат — это POSTING с отрицательным `sale_amount`, а не отдельная
 * `accrued_category`. Установлено сверкой с месячным отчётом «Реализация» за
 * июнь 2026: 77 строк, сумма сошлась с возвратами «Реализации» точно.
 *
 * Ловушка знаков: у возврата отрицательны и `sale_amount`, и `seller_price`,
 * поэтому их частное даёт **+1**. Признак возврата — знак `sale_amount`, а не
 * знак вычисленного количества.
 */
final class OzonAccrualReturnsRawProcessor implements MarketplaceRawProcessorInterface
{
    private const MONEY_SCALE = 2;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OzonListingEnsureService $listingEnsureService,
        private readonly MarketplaceReturnRepository $returnRepository,
        private readonly MarketplaceSaleRepository $saleRepository,
        private readonly MarketplaceCostPriceResolver $costPriceResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(string|StagingRecordType $type, MarketplaceType $marketplace, string $kind = '', ?MarketplaceRawFormat $format = null): bool
    {
        return StagingRecordType::RETURN === $type
            && MarketplaceType::OZON === $marketplace
            && MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY === $format;
    }

    public function process(string $companyId, string $rawDocId): int
    {
        throw new \LogicException(sprintf('%s обрабатывает только батчи: используйте processBatch().', self::class));
    }

    public function processBatch(
        string $companyId,
        MarketplaceType $marketplace,
        array $rawRows,
        ?string $rawDocId = null,
    ): void {
        if ([] === $rawRows) {
            return;
        }

        $company = $this->em->find(Company::class, $companyId);
        if (!$company instanceof Company) {
            throw new \RuntimeException('Company not found: '.$companyId);
        }

        $returns = [];
        foreach ($rawRows as $row) {
            foreach ($this->extractReturns($row) as $return) {
                $returns[] = $return;
            }
        }

        if ([] === $returns) {
            return;
        }

        $listings = $this->listingEnsureService->ensureListings(
            $company,
            array_fill_keys(array_column($returns, 'sku'), null),
        );

        $existing = $this->returnRepository->getExistingExternalIds(
            $companyId,
            array_values(array_unique(array_column($returns, 'externalId'))),
        );

        foreach ($returns as $return) {
            if (isset($existing[$return['externalId']])) {
                continue;
            }

            $listing = $listings[$return['sku']] ?? null;
            if (null === $listing) {
                $this->logger->warning('[Ozon by-day] listing not found for return', [
                    'external_id' => $return['externalId'],
                    'sku' => $return['sku'],
                ]);
                continue;
            }

            $entity = new MarketplaceReturn(
                Uuid::uuid4()->toString(),
                $company,
                $listing,
                MarketplaceType::OZON,
            );

            $entity->setExternalReturnId($return['externalId']);
            $entity->setReturnDate($return['date']);
            $entity->setQuantity($return['quantity']);
            $entity->setRefundAmount($return['refund']);
            // Исходная продажа даёт себестоимость, которую надо сторнировать.
            // Не нашлась — резолвер посчитает по дате возврата, это хуже, но
            // лучше, чем ничего.
            $sale = $this->saleRepository->findByMarketplaceOrderAndSku(
                $company,
                MarketplaceType::OZON,
                $return['saleExternalId'],
                $return['sku'],
            );

            $entity->setCostPrice($this->costPriceResolver->resolveForReturn($listing, $sale, $return['raw'], $return['date']));

            // Ссылка на продажу — то же поле и тот же механизм, что у WB: она
            // уже найдена выше ради себестоимости, и без неё связь возврата с
            // исходным отправлением существует только внутри этого метода.
            if (null !== $sale) {
                $entity->setSale($sale);
            }
            $entity->setRawData($return['raw']);
            if (null !== $rawDocId) {
                $entity->setRawDocumentId($rawDocId);
            }

            $this->em->persist($entity);
            $existing[$return['externalId']] = true;
        }

        $this->em->flush();
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<array{externalId: string, saleExternalId: string, sku: string, date: \DateTimeImmutable, quantity: int, refund: string, raw: array<string, mixed>}>
     */
    private function extractReturns(array $row): array
    {
        $accrualId = (string) ($row['accrual_id'] ?? '');
        $rawDate = $row['date'] ?? null;

        if ('' === $accrualId || !is_string($rawDate)) {
            return [];
        }

        // Номер отправления общий у продажи и её возврата — по нему возврат
        // находит исходную продажу и отражает ТУ ЖЕ себестоимость. Без этого
        // при изменении себестоимости между продажей и возвратом ОПиУ сторнирует
        // не ту сумму.
        $unitNumber = $row['unit_number'] ?? null;
        $postingRef = is_string($unitNumber) && '' !== trim($unitNumber) ? trim($unitNumber) : $accrualId;

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $rawDate);
        if (false === $date) {
            return [];
        }

        $products = $row['posting']['products'] ?? null;
        if (!is_array($products)) {
            return [];
        }

        $returns = [];

        foreach ($products as $index => $product) {
            if (!is_array($product)) {
                continue;
            }

            $commission = $product['commission'] ?? [];
            $saleAmount = $commission['sale_amount']['amount'] ?? null;
            $salePrice = $commission['sale_price']['amount'] ?? null;
            $sellerPrice = $commission['seller_price']['amount'] ?? null;

            if (!is_numeric($saleAmount) || !is_numeric($salePrice) || !is_numeric($sellerPrice)) {
                continue;
            }

            // Не возврат: положительная выручка обрабатывается процессором продаж.
            if ((float) $saleAmount >= 0) {
                continue;
            }

            $quantity = $this->quantity((float) $saleAmount, (float) $sellerPrice, $accrualId);
            if (null === $quantity) {
                continue;
            }

            $sku = (string) ($product['sku'] ?? '');
            if ('' === $sku) {
                $this->logger->warning('[Ozon by-day] product without sku, return skipped', [
                    'accrual_id' => $accrualId,
                ]);
                continue;
            }

            $returns[] = [
                'externalId' => sprintf('ozon-accrual-%s-return-product-%d', $postingRef, $index),
                'saleExternalId' => OzonAccrualSalesRawProcessor::externalId($postingRef, $index),
                'sku' => $sku,
                'date' => $date,
                'quantity' => $quantity,
                // База продавца, как у легаси-строк в этой же таблице: сверка
                // за июнь сошлась точно — marketplace_returns.refund_amount
                // 204 911.00 на 77 строках против суммы |sale_amount| по
                // возвратам 204 911 на тех же 77. Сумма хранится положительной.
                'refund' => $this->money(abs((float) $saleAmount)),
                'raw' => $product,
            ];
        }

        return $returns;
    }

    private function quantity(float $saleAmount, float $sellerPrice, string $accrualId): ?int
    {
        if (0.0 === $sellerPrice) {
            return null;
        }

        // Обе величины отрицательны, поэтому частное уже положительное.
        $raw = $saleAmount / $sellerPrice;
        $rounded = (int) round($raw);

        if ($rounded < 1) {
            $this->logger->warning('[Ozon by-day] non-integer quantity, return skipped', [
                'accrual_id' => $accrualId,
                'ratio' => $raw,
            ]);

            return null;
        }

        // Проверка в деньгах, а не допуском на частное: количество сторнирует
        // себестоимость, и допуск 0.001 на ratio позволил бы вернуть на склад
        // не то число единиц, что было продано. Инвариант — seller_price *
        // quantity = sale_amount в копейках.
        if (0 !== bccomp($this->money($sellerPrice * $rounded), $this->money($saleAmount), self::MONEY_SCALE)) {
            $this->logger->warning('[Ozon by-day] non-integer quantity, return skipped', [
                'accrual_id' => $accrualId,
                'ratio' => $raw,
                'seller_price' => $this->money($sellerPrice),
                'sale_amount' => $this->money($saleAmount),
            ]);

            return null;
        }

        return $rounded;
    }

    private function money(float $value): string
    {
        return number_format($value, self::MONEY_SCALE, '.', '');
    }
}
