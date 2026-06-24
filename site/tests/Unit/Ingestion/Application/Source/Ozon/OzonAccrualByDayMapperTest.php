<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\DTO\MappedTransaction;
use App\Ingestion\Application\Source\Ozon\OzonAccrualByDayMapper;
use App\Ingestion\Application\Source\Ozon\OzonAccrualByDayPreviewMapper;
use App\Ingestion\Application\Source\Ozon\OzonMoneyParser;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Domain\Service\SourceDataHasher;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class OzonAccrualByDayMapperTest extends TestCase
{
    public function testMapsWritableAccrualComponentsToCanonicalTransactions(): void
    {
        $companyId = '19621cff-b028-45d9-9193-11f47ad9a8b2';
        $rawRecord = $this->rawRecord($companyId);

        $transactions = $this->mapper()->map($rawRecord, [[
            'accrual_id' => 53675409100,
            'date' => '2026-06-13',
            'unit_number' => '41774559-0885-1',
            'accrued_category' => 'POSTING',
            'posting' => [
                'products' => [[
                    'delivery' => [
                        'services' => [
                            ['type_id' => 29, 'accrued' => ['amount' => '-7.86', 'currency' => 'RUB']],
                        ],
                    ],
                    'commission' => [
                        'bonus' => ['amount' => '12.79', 'currency' => 'RUB'],
                        'commission' => ['amount' => '-120.05', 'currency' => 'RUB'],
                        'sale_amount' => ['amount' => '66718', 'currency' => 'RUB'],
                    ],
                ]],
            ],
        ]]);

        self::assertCount(4, $transactions);

        $sale = $this->transaction($transactions, 'ozon:accrual-by-day:53675409100:sale:product-0');
        self::assertSame(TransactionType::SALE, $sale->type);
        self::assertSame(TransactionDirection::IN, $sale->direction);
        self::assertSame(6671800, $sale->money->amountMinor());
        self::assertSame('sale:product-0', $sale->sourceData['_ingestion_component']);
        self::assertSame('sale_amount', $sale->sourceData['_ingestion_field']);
        self::assertSame('ozon_revenue', $sale->sourceData['_ozon_category_code']);
        self::assertSame('Выручка', $sale->sourceData['_ozon_category_label']);

        $bonus = $this->transaction($transactions, 'ozon:accrual-by-day:53675409100:bonus:product-0');
        self::assertSame(TransactionType::BONUS, $bonus->type);
        self::assertSame(TransactionDirection::IN, $bonus->direction);
        self::assertSame(1279, $bonus->money->amountMinor());
        self::assertSame('Продажи', $bonus->sourceData['_ozon_category_group']);
        self::assertSame('Баллы за скидки', $bonus->sourceData['_ozon_category_label']);

        $commission = $this->transaction($transactions, 'ozon:accrual-by-day:53675409100:commission:product-0');
        self::assertSame('ozon:accrual-by-day:53675409100:commission:product-0', $commission->externalId);
        self::assertSame(TransactionType::COMMISSION, $commission->type);
        self::assertSame(TransactionDirection::OUT, $commission->direction);
        self::assertSame(12005, $commission->money->amountMinor());
        self::assertSame('2026-06-13 00:00:00', $commission->occurredAt->format('Y-m-d H:i:s'));
        self::assertSame('Europe/Moscow', $commission->sourceTz);
        self::assertSame(OzonResourceType::ACCRUAL_BY_DAY, $commission->sourceData['_ingestion_resource']);
        self::assertSame('commission:product-0', $commission->sourceData['_ingestion_component']);
        self::assertSame('Ozon: Вознаграждение за продажу', $commission->description);

        $delivery = $this->transaction($transactions, 'ozon:accrual-by-day:53675409100:delivery:product-0:service-0:type-29');
        self::assertSame('ozon:accrual-by-day:53675409100:delivery:product-0:service-0:type-29', $delivery->externalId);
        self::assertSame(TransactionType::FEE, $delivery->type);
        self::assertSame(TransactionDirection::OUT, $delivery->direction);
        self::assertSame(786, $delivery->money->amountMinor());
        self::assertSame('29', $delivery->sourceData['_ingestion_type_id']);
        self::assertSame('ozon_logistics', $delivery->sourceData['_ozon_category_code']);
        self::assertSame('Логистика', $delivery->sourceData['_ozon_category_label']);
        self::assertSame(400, $delivery->sourceData['_ozon_category_sort_order']);
        self::assertTrue($delivery->sourceData['_ozon_category_known']);
    }

    public function testBuildsControlSumsFromMappedComponents(): void
    {
        $companyId = '19621cff-b028-45d9-9193-11f47ad9a8b2';
        $rawRecord = $this->rawRecord($companyId);

        $controlSums = $this->mapper()->controlSumForRawRecord($rawRecord, [[
            'accrual_id' => 53675409100,
            'date' => '2026-06-13',
            'accrued_category' => 'POSTING',
            'posting' => [
                'products' => [[
                    'delivery' => [
                        'services' => [
                            ['type_id' => 29, 'accrued' => ['amount' => '-7.86', 'currency' => 'RUB']],
                        ],
                    ],
                    'commission' => [
                        'commission' => ['amount' => '-120.05', 'currency' => 'RUB'],
                        'sale_amount' => ['amount' => '66718', 'currency' => 'RUB'],
                    ],
                ]],
            ],
        ]]);

        self::assertCount(1, $controlSums);
        self::assertSame('RUB', $controlSums[0]->currency);
        // Sale/refund cutover includes all accrual components in the operation group control sum.
        self::assertSame(6684591, $controlSums[0]->amountMinor);
    }

    public function testMapsRefundInActiveMapper(): void
    {
        $companyId = '19621cff-b028-45d9-9193-11f47ad9a8b2';
        $rawRecord = $this->rawRecord($companyId);

        $transactions = $this->mapper()->map($rawRecord, [[
            'accrual_id' => 53675409104,
            'date' => '2026-06-13',
            'accrued_category' => 'POSTING',
            'posting' => [
                'products' => [[
                    'commission' => [
                        'commission' => ['amount' => '1305.02', 'currency' => 'RUB'],
                        'sale_amount' => ['amount' => '-2837.00', 'currency' => 'RUB'],
                    ],
                ]],
            ],
        ]]);

        self::assertCount(2, $transactions);

        $refund = $this->transaction($transactions, 'ozon:accrual-by-day:53675409104:refund:product-0');
        self::assertSame(TransactionType::REFUND, $refund->type);
        self::assertSame(TransactionDirection::OUT, $refund->direction);
        self::assertSame(283700, $refund->money->amountMinor());
        self::assertSame('sale_amount', $refund->sourceData['_ingestion_field']);

        $commission = $this->transaction($transactions, 'ozon:accrual-by-day:53675409104:commission:product-0');
        self::assertSame(TransactionType::COMMISSION, $commission->type);
        self::assertSame(TransactionDirection::IN, $commission->direction);
        self::assertSame(130502, $commission->money->amountMinor());
    }

    /**
     * @param list<MappedTransaction> $transactions
     */
    private function transaction(array $transactions, string $externalId): MappedTransaction
    {
        foreach ($transactions as $transaction) {
            if ($externalId === $transaction->externalId) {
                return $transaction;
            }
        }

        self::fail(sprintf('Mapped transaction with external id "%s" was not found.', $externalId));
    }

    private function mapper(): OzonAccrualByDayMapper
    {
        return new OzonAccrualByDayMapper(new OzonAccrualByDayPreviewMapper(new OzonMoneyParser(), new SourceDataHasher()));
    }

    private function rawRecord(string $companyId): IngestRawRecord
    {
        return new IngestRawRecord(
            companyId: $companyId,
            connectionRef: Uuid::uuid7()->toString(),
            shopRef: Uuid::uuid7()->toString(),
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            externalId: 'accrual-by-day:2026-06-13:2026-06-13',
            storagePath: 'raw.ndjson.gz',
            hash: str_repeat('a', 64),
            byteSize: 100,
            fetchedAt: new \DateTimeImmutable('2026-06-20 20:35:35'),
            syncJobId: Uuid::uuid7()->toString(),
        );
    }
}
