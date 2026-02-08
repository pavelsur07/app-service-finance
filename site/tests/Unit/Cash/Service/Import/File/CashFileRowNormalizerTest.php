<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Service\Import\File;

use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Service\Import\File\CashFileRowNormalizer;
use PHPUnit\Framework\TestCase;

final class CashFileRowNormalizerTest extends TestCase
{
    public function testAmountSingleColumnNegativeValueSetsOutflow(): void
    {
        $normalizer = new CashFileRowNormalizer();

        $result = $normalizer->normalize(
            [
                'Date' => '2025-12-01',
                'Amount' => '-1000',
            ],
            [
                'date' => 'Date',
                'amount' => 'Amount',
            ],
            'RUB'
        );

        self::assertTrue($result['ok']);
        self::assertSame(CashDirection::OUTFLOW, $result['direction']);
        self::assertSame('1000.00', $result['amount']);

        $expected = hash('sha256', 'v1|2025-12-01|1000.00||');
        self::assertSame($expected, $result['externalId']);
    }

    /**
     * @dataProvider inflowOutflowProvider
     */
    public function testInflowOutflowModeDetectsDirection(array $row, CashDirection $direction, string $amount): void
    {
        $normalizer = new CashFileRowNormalizer();

        $result = $normalizer->normalize(
            array_merge(['Date' => '2025-12-01'], $row),
            [
                'date' => 'Date',
                'inflow' => 'Inflow',
                'outflow' => 'Outflow',
            ],
            'RUB'
        );

        self::assertTrue($result['ok']);
        self::assertSame($direction, $result['direction']);
        self::assertSame($amount, $result['amount']);

        $expected = hash('sha256', 'v1|2025-12-01|'.$amount.'||');
        self::assertSame($expected, $result['externalId']);
    }

    public function testDateParsingSupportsKnownFormats(): void
    {
        $normalizer = new CashFileRowNormalizer();

        $resultDotFormat = $normalizer->normalize(
            [
                'Date' => '01.12.2025',
                'Amount' => '1000',
            ],
            [
                'date' => 'Date',
                'amount' => 'Amount',
            ],
            'RUB'
        );

        $resultIsoFormat = $normalizer->normalize(
            [
                'Date' => '2025-12-01',
                'Amount' => '1000',
            ],
            [
                'date' => 'Date',
                'amount' => 'Amount',
            ],
            'RUB'
        );

        self::assertTrue($resultDotFormat['ok']);
        self::assertSame('2025-12-01', $resultDotFormat['occurredAt']?->format('Y-m-d'));

        self::assertTrue($resultIsoFormat['ok']);
        self::assertSame('2025-12-01', $resultIsoFormat['occurredAt']?->format('Y-m-d'));
    }

    public function testEmptyCurrencyUsesDefault(): void
    {
        $normalizer = new CashFileRowNormalizer();

        $result = $normalizer->normalize(
            [
                'Date' => '2025-12-01',
                'Amount' => '1000',
                'Currency' => '',
            ],
            [
                'date' => 'Date',
                'amount' => 'Amount',
                'currency' => 'Currency',
            ],
            'RUB'
        );

        self::assertTrue($result['ok']);
        self::assertSame('RUB', $result['currency']);

        $expected = hash('sha256', 'v1|2025-12-01|1000.00||');
        self::assertSame($expected, $result['externalId']);
    }

    public function testEmptyCounterpartyIsNull(): void
    {
        $normalizer = new CashFileRowNormalizer();

        $result = $normalizer->normalize(
            [
                'Date' => '2025-12-01',
                'Amount' => '1000',
                'Counterparty' => '',
            ],
            [
                'date' => 'Date',
                'amount' => 'Amount',
                'counterparty' => 'Counterparty',
            ],
            'RUB'
        );

        self::assertTrue($result['ok']);
        self::assertNull($result['counterpartyName']);

        $expected = hash('sha256', 'v1|2025-12-01|1000.00||');
        self::assertSame($expected, $result['externalId']);
    }

    public function testExternalIdChangesWhenDescriptionChanges(): void
    {
        $normalizer = new CashFileRowNormalizer();

        $a = $normalizer->normalize(
            [
                'Date' => '2025-12-01',
                'Amount' => '1000',
                'Description' => 'Оплата по счету 123',
            ],
            [
                'date' => 'Date',
                'amount' => 'Amount',
                'description' => 'Description',
            ],
            'RUB'
        );

        $b = $normalizer->normalize(
            [
                'Date' => '2025-12-01',
                'Amount' => '1000',
                'Description' => 'Оплата по счету 124',
            ],
            [
                'date' => 'Date',
                'amount' => 'Amount',
                'description' => 'Description',
            ],
            'RUB'
        );

        self::assertTrue($a['ok']);
        self::assertTrue($b['ok']);
        self::assertNotSame($a['externalId'], $b['externalId']);

        $expectedA = hash('sha256', 'v1|2025-12-01|1000.00||оплата по счету 123');
        self::assertSame($expectedA, $a['externalId']);
    }

    /**
     * @return iterable<string, array{0: array<string, string|null>, 1: CashDirection, 2: string}>
     */
    public static function inflowOutflowProvider(): iterable
    {
        yield 'inflow' => [
            ['Inflow' => '2500', 'Outflow' => null],
            CashDirection::INFLOW,
            '2500.00',
        ];

        yield 'outflow' => [
            ['Inflow' => null, 'Outflow' => '1200.50'],
            CashDirection::OUTFLOW,
            '1200.50',
        ];
    }
}
