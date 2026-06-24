<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\Source\Ozon\OzonAccrualByDayPreviewMapper;
use App\Ingestion\Application\Source\Ozon\OzonAccrualPreviewTransaction;
use App\Ingestion\Application\Source\Ozon\OzonMoneyParser;
use App\Ingestion\Domain\Service\SourceDataHasher;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use PHPUnit\Framework\TestCase;

final class OzonAccrualByDayPreviewMapperTest extends TestCase
{
    public function testBuildsPreviewRowsForWritableAccrualComponents(): void
    {
        $rows = $this->mapper()->preview(
            '19621cff-b028-45d9-9193-11f47ad9a8b2',
            [
                [
                    'accrual_id' => 53675409100,
                    'date' => '2026-06-13',
                    'unit_number' => '41774559-0885-1',
                    'accrued_category' => 'POSTING',
                    'posting' => [
                        'products' => [[
                            'delivery' => [
                                'services' => [
                                    ['type_id' => 29, 'accrued' => ['amount' => '-7.86', 'currency' => 'RUB']],
                                    ['type_id' => 45, 'accrued' => ['amount' => '-15', 'currency' => 'RUB']],
                                ],
                            ],
                            'commission' => [
                                'bonus' => ['amount' => '12.79', 'currency' => 'RUB'],
                                'commission' => ['amount' => '-120.05', 'currency' => 'RUB'],
                                'sale_amount' => ['amount' => '66718', 'currency' => 'RUB'],
                                'sale_commission' => ['amount' => '-120.05', 'currency' => 'RUB'],
                            ],
                        ]],
                    ],
                ],
                [
                    'accrual_id' => 53675409101,
                    'date' => '2026-06-13',
                    'accrued_category' => 'ITEM',
                    'item_fees' => [
                        'fees' => [[
                            'fees' => [
                                ['type_id' => 1, 'accrued' => ['amount' => '18.66', 'currency' => 'RUB']],
                            ],
                        ]],
                    ],
                ],
                [
                    'accrual_id' => 53675409102,
                    'date' => '2026-06-14',
                    'accrued_category' => 'NON_ITEM',
                    'non_item_fee' => ['type_id' => 46, 'accrued' => ['amount' => '-78.28', 'currency' => 'RUB']],
                ],
                [
                    'accrual_id' => 53675409103,
                    'date' => '2026-06-14',
                    'accrued_category' => 'CONTAINER',
                    'container_fees' => [
                        'fees' => [
                            ['type_id' => 77, 'accrued' => ['amount' => '-3.50', 'currency' => 'RUB']],
                        ],
                    ],
                ],
            ],
            includeSaleRefund: true,
        );

        self::assertCount(8, $rows);

        $sale = $this->row($rows, 'sale:product-0');
        self::assertSame(TransactionType::SALE, $sale->type);
        self::assertSame(TransactionDirection::IN, $sale->direction);
        self::assertSame(6671800, $sale->amountMinor);
        self::assertSame('sale_amount', $sale->field);
        self::assertSame('ozon:accrual-by-day:53675409100:sale:product-0', $sale->sourceKey);
        self::assertSame('ozon_revenue', $sale->ozonCategoryCode);
        self::assertSame('Выручка', $sale->ozonCategoryLabel);
        self::assertSame('Продажи', $sale->ozonCategoryGroup);

        $bonus = $this->row($rows, 'bonus:product-0');
        self::assertSame(TransactionType::BONUS, $bonus->type);
        self::assertSame(TransactionDirection::IN, $bonus->direction);
        self::assertSame(1279, $bonus->amountMinor);
        self::assertSame('bonus', $bonus->field);
        self::assertSame('Баллы за скидки', $bonus->ozonCategoryLabel);

        $commission = $this->row($rows, 'commission:product-0');
        self::assertSame(TransactionType::COMMISSION, $commission->type);
        self::assertSame(TransactionDirection::OUT, $commission->direction);
        self::assertSame(12005, $commission->amountMinor);
        self::assertSame(-12005, $commission->signedAmountMinor());
        self::assertSame('commission', $commission->field);
        self::assertSame('ozon:accrual-by-day:53675409100:commission:product-0', $commission->sourceKey);
        self::assertSame('Вознаграждение за продажу', $commission->ozonCategoryLabel);

        $delivery = $this->row($rows, 'delivery:product-0:service-0:type-29');
        self::assertSame(TransactionType::FEE, $delivery->type);
        self::assertSame(TransactionDirection::OUT, $delivery->direction);
        self::assertSame(786, $delivery->amountMinor);
        self::assertSame('29', $delivery->typeId);
        self::assertSame('ozon_logistics', $delivery->ozonCategoryCode);
        self::assertSame('Логистика', $delivery->ozonCategoryLabel);

        $item = $this->row($rows, 'item_fee:group-0:fee-0:type-1');
        self::assertSame(TransactionType::FEE, $item->type);
        self::assertSame(TransactionDirection::IN, $item->direction);
        self::assertSame(1866, $item->amountMinor);
        self::assertFalse($item->ozonCategoryKnown);

        $nonItem = $this->row($rows, 'non_item_fee:type-46');
        self::assertSame(TransactionType::OTHER, $nonItem->type);
        self::assertSame(TransactionDirection::OUT, $nonItem->direction);
        self::assertSame(7828, $nonItem->amountMinor);
        self::assertSame('Неизвестные категории Ozon', $nonItem->ozonCategoryGroup);

        $container = $this->row($rows, 'container_fee:fees:0:type-77');
        self::assertSame(TransactionType::FEE, $container->type);
        self::assertSame(TransactionDirection::OUT, $container->direction);
        self::assertSame(350, $container->amountMinor);
        self::assertSame('ozon_cargo_place_item_processing', $container->ozonCategoryCode);
        self::assertSame('Обработка товара в составе грузоместа', $container->ozonCategoryLabel);
    }

    public function testOmitsSaleAndRefundWhenExplicitlyExcluded(): void
    {
        $rows = $this->mapper()->preview(
            '19621cff-b028-45d9-9193-11f47ad9a8b2',
            [[
                'accrual_id' => 53675409100,
                'date' => '2026-06-13',
                'accrued_category' => 'POSTING',
                'posting' => [
                    'products' => [[
                        'commission' => [
                            'commission' => ['amount' => '-120.05', 'currency' => 'RUB'],
                            'sale_amount' => ['amount' => '66718', 'currency' => 'RUB'],
                        ],
                    ]],
                ],
            ]],
            includeSaleRefund: false,
        );

        self::assertCount(1, $rows);
        self::assertSame(TransactionType::COMMISSION, $rows[0]->type);
    }

    public function testPartnerProgramAliasesUseCanonicalSourceKeyAndFirstNonZeroField(): void
    {
        $rows = $this->mapper()->preview(
            '19621cff-b028-45d9-9193-11f47ad9a8b2',
            [[
                'accrual_id' => 53675409100,
                'date' => '2026-06-13',
                'accrued_category' => 'POSTING',
                'posting' => [
                    'products' => [[
                        'commission' => [
                            'partner_program' => ['amount' => '10.00', 'currency' => 'RUB'],
                            'partner_programs' => ['amount' => '20.00', 'currency' => 'RUB'],
                        ],
                    ]],
                ],
            ]],
        );

        self::assertCount(1, $rows);

        $partnerProgram = $this->row($rows, 'partner_programs:product-0');
        self::assertSame(TransactionType::BONUS, $partnerProgram->type);
        self::assertSame(TransactionDirection::IN, $partnerProgram->direction);
        self::assertSame(1000, $partnerProgram->amountMinor);
        self::assertSame('partner_program', $partnerProgram->field);
        self::assertSame('ozon:accrual-by-day:53675409100:partner_programs:product-0', $partnerProgram->sourceKey);
        self::assertSame('ozon_partner_programs', $partnerProgram->ozonCategoryCode);

        $rowsWithCoinvestmentAlias = $this->mapper()->preview(
            '19621cff-b028-45d9-9193-11f47ad9a8b2',
            [[
                'accrual_id' => 53675409100,
                'date' => '2026-06-13',
                'accrued_category' => 'POSTING',
                'posting' => [
                    'products' => [[
                        'commission' => [
                            'coinvestment' => ['amount' => '10.00', 'currency' => 'RUB'],
                        ],
                    ]],
                ],
            ]],
        );

        self::assertCount(1, $rowsWithCoinvestmentAlias);
        self::assertSame($partnerProgram->sourceKey, $rowsWithCoinvestmentAlias[0]->sourceKey);
        self::assertSame('coinvestment', $rowsWithCoinvestmentAlias[0]->field);
    }

    public function testBuildsRefundFromNegativeSaleAmountAndKeepsCommissionStorno(): void
    {
        $rows = $this->mapper()->preview('19621cff-b028-45d9-9193-11f47ad9a8b2', [[
            'accrual_id' => 53675409104,
            'date' => '2026-06-13',
            'unit_number' => '41774559-0885-1',
            'accrued_category' => 'POSTING',
            'posting' => [
                'products' => [[
                    'commission' => [
                        'commission' => ['amount' => '1305.02', 'currency' => 'RUB'],
                        'sale_amount' => ['amount' => '-2837.00', 'currency' => 'RUB'],
                    ],
                ]],
            ],
        ]], includeSaleRefund: true);

        self::assertCount(2, $rows);

        $refund = $this->row($rows, 'refund:product-0');
        self::assertSame(TransactionType::REFUND, $refund->type);
        self::assertSame(TransactionDirection::OUT, $refund->direction);
        self::assertSame(283700, $refund->amountMinor);
        self::assertSame(-283700, $refund->signedAmountMinor());
        self::assertSame('sale_amount', $refund->field);
        self::assertSame('ozon:accrual-by-day:53675409104:refund:product-0', $refund->sourceKey);

        $commission = $this->row($rows, 'commission:product-0');
        self::assertSame(TransactionType::COMMISSION, $commission->type);
        self::assertSame(TransactionDirection::IN, $commission->direction);
        self::assertSame(130502, $commission->amountMinor);
    }

    public function testIgnoresUnknownCategoriesAndZeroAmounts(): void
    {
        $rows = $this->mapper()->preview('19621cff-b028-45d9-9193-11f47ad9a8b2', [
            [
                'date' => '2026-06-13',
                'accrued_category' => 'UNKNOWN',
                'total_amount' => ['amount' => '99', 'currency' => 'RUB'],
            ],
            [
                'accrual_id' => 53675409100,
                'date' => '2026-06-13',
                'accrued_category' => 'POSTING',
                'posting' => [
                    'products' => [[
                        'delivery' => [
                            'services' => [
                                ['type_id' => 29, 'accrued' => ['amount' => '0', 'currency' => 'RUB']],
                            ],
                        ],
                        'commission' => [
                            'commission' => ['amount' => '0', 'currency' => 'RUB'],
                        ],
                    ]],
                ],
            ],
        ]);

        self::assertSame([], $rows);
    }

    public function testFiltersRowsOutsideRequestedDateWindow(): void
    {
        $rows = $this->mapper()->preview(
            '19621cff-b028-45d9-9193-11f47ad9a8b2',
            [
                $this->postingCommissionRow(53675409100, '2026-06-14', '-10'),
                $this->postingCommissionRow(53675409101, '2026-06-15', '-20'),
                $this->postingCommissionRow(53675409102, '2026-06-16', '-30'),
            ],
            new \DateTimeImmutable('2026-06-15'),
            new \DateTimeImmutable('2026-06-15'),
        );

        self::assertCount(1, $rows);
        self::assertSame('2026-06-15', $rows[0]->date);
        self::assertSame(2000, $rows[0]->amountMinor);
        self::assertSame('ozon:accrual-by-day:53675409101:commission:product-0', $rows[0]->sourceKey);
    }

    public function testFallbackAccrualIdIsIndependentOfRowOrder(): void
    {
        $rowA = $this->fallbackPostingRow('2026-06-13', '-10', '41774559-0885-1');
        $rowB = $this->fallbackPostingRow('2026-06-14', '-20', '41774559-0885-2');

        $forward = $this->mapper()->preview('19621cff-b028-45d9-9193-11f47ad9a8b2', [$rowA, $rowB]);
        $reversed = $this->mapper()->preview('19621cff-b028-45d9-9193-11f47ad9a8b2', [$rowB, $rowA]);

        $forwardKeys = array_map(static fn (OzonAccrualPreviewTransaction $row): string => $row->sourceKey, $forward);
        $reversedKeys = array_map(static fn (OzonAccrualPreviewTransaction $row): string => $row->sourceKey, $reversed);

        sort($forwardKeys);
        sort($reversedKeys);

        self::assertSame($forwardKeys, $reversedKeys);
        self::assertStringContainsString('ozon:accrual-by-day:fallback-', $forward[0]->sourceKey);
    }

    public function testFallbackAccrualIdDiffersForDistinctRowContent(): void
    {
        $rowA = $this->fallbackPostingRow('2026-06-13', '-10', '41774559-0885-1');
        $rowB = $this->fallbackPostingRow('2026-06-13', '-11', '41774559-0885-1');

        $idA = $this->mapper()->preview('19621cff-b028-45d9-9193-11f47ad9a8b2', [$rowA])[0]->sourceKey;
        $idB = $this->mapper()->preview('19621cff-b028-45d9-9193-11f47ad9a8b2', [$rowB])[0]->sourceKey;

        self::assertNotSame($idA, $idB);
    }

    /**
     * @param list<OzonAccrualPreviewTransaction> $rows
     */
    private function row(array $rows, string $component): OzonAccrualPreviewTransaction
    {
        foreach ($rows as $row) {
            if ($component === $row->component) {
                return $row;
            }
        }

        self::fail(sprintf('Preview row with component "%s" was not found.', $component));
    }

    private function mapper(): OzonAccrualByDayPreviewMapper
    {
        return new OzonAccrualByDayPreviewMapper(new OzonMoneyParser(), new SourceDataHasher());
    }

    /**
     * Posting row WITHOUT an accrual_id, forcing the deterministic fallback id.
     *
     * @return array<string, mixed>
     */
    private function fallbackPostingRow(string $date, string $amount, string $unitNumber): array
    {
        return [
            'date' => $date,
            'unit_number' => $unitNumber,
            'accrued_category' => 'POSTING',
            'posting' => [
                'products' => [[
                    'commission' => [
                        'commission' => ['amount' => $amount, 'currency' => 'RUB'],
                    ],
                ]],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function postingCommissionRow(int $accrualId, string $date, string $amount): array
    {
        return [
            'accrual_id' => $accrualId,
            'date' => $date,
            'accrued_category' => 'POSTING',
            'posting' => [
                'products' => [[
                    'commission' => [
                        'commission' => ['amount' => $amount, 'currency' => 'RUB'],
                    ],
                ]],
            ],
        ];
    }
}
