<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Company\Entity\Company;
use App\Marketplace\Application\Service\MarketplaceCostPriceResolver;
use App\Marketplace\Application\Service\OzonListingEnsureService;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Repository\MarketplaceSaleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Продажи из начислений Ozon `/v1/finance/accrual/by-day`.
 *
 * Работает рядом с `OzonSalesRawProcessor`, который остаётся обслуживать
 * 967 документов снятого формата v3.
 *
 * **Выручка считается из `sale_amount` — базы цены продавца.** Это та же база,
 * что у легаси-строк в этой же таблице: сверка за июнь 2026 по одному кабинету
 * сошлась точно — `marketplace_sales.total_revenue` 1 950 549.00 на 750 строках
 * против суммы `sale_amount` по продажам 1 950 549 на тех же 750 строках.
 *
 * `sale_price` (цена покупателя со скидками Ozon) для этой таблицы НЕ годится,
 * хотя и совпадает с базой месячного отчёта «Реализация»: у него другой
 * потребитель. Записать сюда 866 631.91 вместо 1 950 549 значило бы сменить
 * базу посреди таблицы и молча занизить выручку в 2.25 раза для всего, что её
 * читает — закрытия месяца и аналитики.
 *
 * **Количество выводится как `sale_amount / seller_price`** — обе величины в
 * одной базе. На всей проверенной выгрузке оно равно единице, но формула
 * переживёт день, когда Ozon пришлёт агрегат. Нецелое частное означает, что
 * предположение о базе неверно; такая строка пропускается и логируется, а не
 * округляется молча.
 */
final class OzonAccrualSalesRawProcessor implements MarketplaceRawProcessorInterface
{
    private const MONEY_SCALE = 2;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OzonListingEnsureService $listingEnsureService,
        private readonly MarketplaceSaleRepository $saleRepository,
        private readonly MarketplaceCostPriceResolver $costPriceResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(string|StagingRecordType $type, MarketplaceType $marketplace, string $kind = '', ?MarketplaceRawFormat $format = null): bool
    {
        return StagingRecordType::SALE === $type
            && MarketplaceType::OZON === $marketplace
            && MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY === $format;
    }

    public function process(string $companyId, string $rawDocId): int
    {
        // Продажи by-day идут только батчем из конвейера. Молчаливый ноль здесь
        // спрятал бы ошибку вызова, поэтому падаем громко.
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

        $sales = [];
        foreach ($rawRows as $row) {
            foreach ($this->extractSales($row) as $sale) {
                $sales[] = $sale;
            }
        }

        if ([] === $sales) {
            return;
        }

        // by-day не несёт наименования товара — только sku.
        $listings = $this->listingEnsureService->ensureListings(
            $company,
            array_fill_keys(array_column($sales, 'sku'), null),
        );

        $existing = $this->saleRepository->getExistingExternalIds(
            $companyId,
            array_values(array_unique(array_column($sales, 'externalId'))),
        );

        foreach ($sales as $sale) {
            if (isset($existing[$sale['externalId']])) {
                continue;
            }

            $listing = $listings[$sale['sku']] ?? null;
            if (null === $listing) {
                $this->logger->warning('[Ozon by-day] listing not found for sale', [
                    'external_id' => $sale['externalId'],
                    'sku' => $sale['sku'],
                ]);
                continue;
            }

            $entity = new MarketplaceSale(
                Uuid::uuid4()->toString(),
                $company,
                $listing,
                MarketplaceType::OZON,
            );

            $entity->setExternalOrderId($sale['externalId']);
            $entity->setSaleDate($sale['date']);
            $entity->setQuantity($sale['quantity']);
            $entity->setPricePerUnit($sale['pricePerUnit']);
            $entity->setTotalRevenue($sale['totalRevenue']);
            $entity->setCostPrice($this->costPriceResolver->resolveForSale($listing, $sale['date']));
            $entity->setRawData($sale['raw']);
            if (null !== $rawDocId) {
                $entity->setRawDocumentId($rawDocId);
            }

            $this->em->persist($entity);
            $existing[$sale['externalId']] = true;
        }

        $this->em->flush();
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<array{externalId: string, sku: string, date: \DateTimeImmutable, quantity: int, pricePerUnit: string, totalRevenue: string, raw: array<string, mixed>}>
     */
    private function extractSales(array $row): array
    {
        $accrualId = (string) ($row['accrual_id'] ?? '');
        $date = $row['date'] ?? null;

        if ('' === $accrualId || !is_string($date)) {
            return [];
        }

        // Ключ строится на номере отправления, а не на accrual_id: тот же номер
        // несёт и возврат этого товара, и по нему возврат находит исходную
        // продажу, чтобы отразить ту же себестоимость. accrual_id у возврата
        // другой и связать по нему нечего.
        $postingRef = $this->postingRef($row, $accrualId);

        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (false === $day) {
            return [];
        }

        $products = $row['posting']['products'] ?? null;
        if (!is_array($products)) {
            return [];
        }

        $sales = [];

        foreach ($products as $index => $product) {
            if (!is_array($product)) {
                continue;
            }

            $commission = $product['commission'] ?? [];
            $saleAmount = $commission['sale_amount']['amount'] ?? null;
            $salePrice = $commission['sale_price']['amount'] ?? null;
            $sellerPrice = $commission['seller_price']['amount'] ?? null;

            // Отправление без выручки: только логистика и услуги.
            if (!is_numeric($saleAmount) || !is_numeric($salePrice) || !is_numeric($sellerPrice)) {
                continue;
            }

            $quantity = $this->quantity((float) $saleAmount, (float) $sellerPrice, $accrualId);
            if (null === $quantity) {
                continue;
            }

            $sku = (string) ($product['sku'] ?? '');
            if ('' === $sku) {
                // Пустой sku создал бы общий листинг-пустышку, к которому
                // прицепились бы несвязанные финансовые записи.
                $this->logger->warning('[Ozon by-day] product without sku, sale skipped', [
                    'accrual_id' => $accrualId,
                ]);
                continue;
            }

            $sales[] = [
                // Ключ детерминирован: повторный прогон дня даёт те же значения,
                // поэтому версии вида _v2 из легаси-пути здесь не нужны.
                'externalId' => self::externalId($postingRef, $index),
                'sku' => $sku,
                'date' => $day,
                'quantity' => $quantity,
                // Цена единицы и итог в одной базе: их произведение обязано
                // сходиться с sale_amount, иначе строка внутренне противоречива.
                'pricePerUnit' => $this->money((float) $sellerPrice),
                'totalRevenue' => $this->money((float) $saleAmount),
                'raw' => $product,
            ];
        }

        return $sales;
    }

    /**
     * Ключ продажи, вычислимый и со стороны возврата.
     */
    public static function externalId(string $postingRef, int $productIndex): string
    {
        return sprintf('ozon-accrual-%s-product-%d', $postingRef, $productIndex);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function postingRef(array $row, string $accrualId): string
    {
        $unitNumber = $row['unit_number'] ?? null;

        return is_string($unitNumber) && '' !== trim($unitNumber) ? trim($unitNumber) : $accrualId;
    }

    private function quantity(float $saleAmount, float $sellerPrice, string $accrualId): ?int
    {
        if (0.0 === $sellerPrice) {
            $this->logger->warning('[Ozon by-day] seller_price is zero, cannot derive quantity', [
                'accrual_id' => $accrualId,
            ]);

            return null;
        }

        $raw = $saleAmount / $sellerPrice;
        $rounded = (int) round($raw);

        if ($rounded < 1) {
            $this->logger->warning('[Ozon by-day] non-integer quantity, sale skipped', [
                'accrual_id' => $accrualId,
                'ratio' => $raw,
            ]);

            return null;
        }

        // Проверка идёт в деньгах, а не в допуске на частное. Строка записывает
        // цену единицы и итог по отдельности, поэтому её внутренний инвариант —
        // seller_price * quantity = sale_amount в копейках. Допуск 0.001 на
        // частное этому не равносилен: при sale_amount 1000.50 и seller_price
        // 1000.00 частное 1.0005 прошло бы, и получилась бы строка, где
        // quantity * pricePerUnit не сходится с totalRevenue.
        if (0 !== bccomp($this->money($sellerPrice * $rounded), $this->money($saleAmount), self::MONEY_SCALE)) {
            // Расхождение означает, что допущение о базе неверно. Округлить
            // молча — значит подделать финансовую строку.
            $this->logger->warning('[Ozon by-day] non-integer quantity, sale skipped', [
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
