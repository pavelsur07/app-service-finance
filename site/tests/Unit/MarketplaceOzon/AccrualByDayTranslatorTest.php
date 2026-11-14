<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceOzon;

use App\MarketplaceOperations\Enum\OperationKind;
use App\MarketplaceOzon\Domain\AccrualByDayTranslator;
use App\MarketplaceOzon\Domain\AccrualComponent;
use App\MarketplaceOzon\Domain\OzonAccrualTypeSeed;
use App\MarketplaceOzon\Infrastructure\Query\OzonTranslationStore;
use App\Shared\Domain\ValueObject\Money;
use PHPUnit\Framework\TestCase;

final class AccrualByDayTranslatorTest extends TestCase
{
    private AccrualByDayTranslator $translator;

    protected function setUp(): void
    {
        $this->translator = new AccrualByDayTranslator();
    }

    public function testFixtureComponentsAddUpToTotalAmountWithoutUnexplained(): void
    {
        foreach ($this->fixture() as $accrual) {
            $components = $this->translator->translate('k', $accrual);
            $id = (string) $accrual['accrual_id'];

            self::assertSame([], array_values(array_filter($components, static fn (AccrualComponent $c): bool => AccrualByDayTranslator::UNEXPLAINED === $c->sourceType)), 'accrual '.$id);
            self::assertSame(Money::fromString($accrual['total_amount']['amount'], 'RUB')->amountMinor(), $this->sum($components), 'accrual '.$id);
        }
    }

    public function testSaleMinusSppMinusCoinvestmentIsBuyerPrice(): void
    {
        $checked = 0;
        foreach ($this->fixture() as $accrual) {
            foreach ($accrual['posting']['products'] ?? [] as $product) {
                $commission = $product['commission'] ?? null;
                if (!\is_array($commission) || !isset($commission['sale_price'])) {
                    continue;
                }
                $components = $this->translator->translate('k', ['accrued_category' => 'POSTING', 'posting' => ['products' => [$product]], 'total_amount' => $commission['sale_amount']]);
                $byKind = [];
                foreach ($components as $c) {
                    $key = $c->kind->value ?? $c->sourceType;
                    $byKind[$key] = ($byKind[$key] ?? 0) + $c->amount->amountMinor();
                }
                $sale = $byKind['sale'] ?? $byKind['return'];
                self::assertSame(Money::fromString($commission['sale_price']['amount'], 'RUB')->amountMinor(), $sale - ($byKind['spp'] ?? 0) - ($byKind['coinvestment'] ?? 0));
                ++$checked;
            }
        }
        self::assertGreaterThan(0, $checked);
    }

    public function testPostingSaleAndReturnComponents(): void
    {
        $sale = $this->translator->translate('ozon:accrual-by-day:1', $this->posting('2999', '1818.74', '11.69', '-1379.54', [['type_id' => 32, 'accrued' => ['amount' => '-118', 'currency' => 'RUB']]], 2));

        self::assertSame([
            ['ozon:field:sale_amount', 299900, 'sale', '700', 2],
            ['ozon:field:bonus', 181874, 'spp', '700', null],
            ['ozon:field:coinvestment', 1169, 'coinvestment', '700', null],
            ['ozon:field:sale_commission', -137954, 'commission', '700', null],
            ['ozon:service:32', -11800, null, '700', null],
        ], $this->rows($sale));

        $return = $this->translator->translate('k', $this->posting('-2647', '-1154.8', '-14.77', '1217.62', [], 1));
        self::assertSame(OperationKind::RETURN, $return[0]->kind);
        self::assertSame(-264700, $return[0]->amount->amountMinor());
    }

    public function testItemAndNonItemFees(): void
    {
        $item = $this->translator->translate('k', [
            'accrued_category' => 'ITEM',
            'total_amount' => ['amount' => '-60.08', 'currency' => 'RUB'],
            'item_fees' => ['fees' => [['sku' => 3213984830, 'fees' => [['accrued' => ['amount' => '-60.08', 'currency' => 'RUB'], 'type_id' => 1]], 'quantity' => 1]]],
        ]);
        self::assertSame([['ozon:service:1', -6008, null, '3213984830', null]], $this->rows($item));

        $nonItem = $this->translator->translate('k', [
            'accrued_category' => 'NON_ITEM',
            'total_amount' => ['amount' => '-1949.98', 'currency' => 'RUB'],
            'non_item_fee' => ['accrued' => ['amount' => '-1949.98', 'currency' => 'RUB'], 'type_id' => 46],
        ]);
        self::assertSame([['ozon:service:46', -194998, null, null, null]], $this->rows($nonItem));
    }

    public function testWhatIsNotExplainedGoesToUnexplained(): void
    {
        // неизвестная категория — весь итог
        self::assertSame([[AccrualByDayTranslator::UNEXPLAINED, -500, null, null, null]], $this->rows($this->translator->translate('k', [
            'accrued_category' => 'CONTAINER',
            'total_amount' => ['amount' => '-5.00', 'currency' => 'RUB'],
            'container_fees' => [['accrued' => ['amount' => '-5.00', 'currency' => 'RUB'], 'type_id' => 9]],
        ])));

        // услуга без type_id и нулевая услуга: в итог попадает только расхождение
        self::assertSame([['ozon:service:32', -1000, null, '7', null], [AccrualByDayTranslator::UNEXPLAINED, -300, null, null, null]], $this->rows($this->translator->translate('k', [
            'accrued_category' => 'POSTING',
            'total_amount' => ['amount' => '-13.00', 'currency' => 'RUB'],
            'posting' => ['products' => [['sku' => 7, 'commission' => null, 'delivery' => ['services' => [
                ['type_id' => 32, 'accrued' => ['amount' => '-10', 'currency' => 'RUB']],
                ['accrued' => ['amount' => '-3', 'currency' => 'RUB']],
                ['type_id' => 29, 'accrued' => ['amount' => '0', 'currency' => 'RUB']],
            ]]]]],
        ])));
    }

    public function testSeedHasNamesForAllTypesAndKindsForSeenOnes(): void
    {
        foreach (OzonAccrualTypeSeed::TYPES as $typeId => [$name]) {
            self::assertNotSame('', trim($name), 'type '.$typeId);
        }
        self::assertSame(OperationKind::LOGISTICS_TO, OzonTranslationStore::seedKind('ozon:service:32'));
        self::assertNull(OzonTranslationStore::seedKind('ozon:service:12'));
        self::assertNull(OzonTranslationStore::seedKind(AccrualByDayTranslator::UNEXPLAINED));
        self::assertSame('Логистика', OzonTranslationStore::seedName('ozon:service:32'));
        self::assertSame('Тип Ozon 9999', OzonTranslationStore::seedName('ozon:service:9999'));
    }

    /**
     * @param list<array<string, mixed>> $services
     *
     * @return array<string, mixed>
     */
    private function posting(string $sale, string $bonus, string $coinvestment, string $commission, array $services, int $quantity): array
    {
        $total = bcadd($sale, $commission, 2);
        foreach ($services as $service) {
            $total = bcadd($total, $service['accrued']['amount'], 2);
        }
        $money = static fn (string $a): array => ['amount' => $a, 'currency' => 'RUB'];

        return [
            'accrued_category' => 'POSTING',
            'total_amount' => $money($total),
            'posting' => ['products' => [[
                'sku' => 700,
                'quantity' => $quantity,
                'commission' => ['sale_amount' => $money($sale), 'seller_price' => $money('1'), 'bonus' => $money($bonus), 'coinvestment' => $money($coinvestment), 'sale_commission' => $money($commission), 'commission' => $money($commission)],
                'delivery' => ['services' => $services],
            ]]],
        ];
    }

    /**
     * @param list<AccrualComponent> $components
     *
     * @return list<array{0: string, 1: int, 2: string|null, 3: string|null, 4: int|null}>
     */
    private function rows(array $components): array
    {
        return array_map(static fn (AccrualComponent $c): array => [$c->sourceType, $c->amount->amountMinor(), $c->kind?->value, $c->sku, $c->quantity], $components);
    }

    /**
     * @param list<AccrualComponent> $components
     */
    private function sum(array $components): int
    {
        $sum = 0;
        foreach ($components as $c) {
            if (!AccrualByDayTranslator::isInformational($c)) {
                $sum += $c->amount->amountMinor();
            }
        }

        return $sum;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fixture(): array
    {
        $data = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/Marketplace/Ozon/accrual_by_day_cases.json'), true, 512, \JSON_THROW_ON_ERROR);

        return $data['accruals'];
    }
}
