<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\DTO\AdRawEntry;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdRawDataParser;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonPerformanceCsvParser;
use PHPUnit\Framework\TestCase;

/**
 * Unit-тесты {@see OzonPerformanceCsvParser} — Task-12-test.
 *
 * Покрываемые инварианты:
 *  - supports('ozon') → true (все остальные площадки делегируются тегированным
 *    парсерам в ProcessAdRawDocumentAction::selectParser);
 *  - раз payload начинается с `batch_id=` — парсится CSV напрямую, а не JSON;
 *  - payload без маркера — делегируется в композитный JSON-парсер
 *    (обратная совместимость с legacy-документами старого Messenger-pipeline'а);
 *  - preamble + footer корректно срезаются;
 *  - campaignId берётся из preamble; fallback — из filename в маркере;
 *  - campaignName = весь preamble-текст (что пользователь видит в Ozon);
 *  - агрегация по (campaignId, parentSku): cost/impressions/clicks суммируются;
 *  - десятичная запятая `1152,19` → `1152.19`;
 *  - корректный zip из 3 CSV (mock через конкатенацию через Action-уровень
 *    не нужен — парсер работает с одним payload'ом; multi-CSV тестируется
 *    отдельно через вызов parse() N раз, один на CSV).
 */
final class OzonPerformanceCsvParserTest extends TestCase
{
    private OzonPerformanceCsvParser $parser;

    protected function setUp(): void
    {
        $this->parser = new OzonPerformanceCsvParser(
            new OzonAdRawDataParser(),
        );
    }

    public function testSupportsOzonOnly(): void
    {
        self::assertTrue($this->parser->supports(MarketplaceType::OZON->value));
        self::assertFalse($this->parser->supports(MarketplaceType::WILDBERRIES->value));
        self::assertFalse($this->parser->supports('unknown'));
    }

    public function testParsesCsvWithPreambleAndFooter(): void
    {
        $csv = "\xEF\xBB\xBF;Кампания по продвижению товаров № 14275771, период 17.04.2026-18.04.2026\n"
            ."День;sku;Название товара;Показы;Клики;Расход, ₽, с НДС\n"
            ."17.04.2026;286085455;Велосипедки;4399;218;1152,19\n"
            ."18.04.2026;286085455;Велосипедки;5000;250;1375,50\n"
            ."Всего;;;;9399;468;2527,69\n";

        $payload = $this->wrapWithMarker(
            'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            '14275771_17.04.2026-18.04.2026.csv',
            $csv,
        );

        $entries = $this->parser->parse($payload);

        self::assertCount(1, $entries, 'Две data-строки с одним (campaign, sku) → один агрегат');
        /** @var AdRawEntry $entry */
        $entry = $entries[0];
        self::assertSame('14275771', $entry->campaignId, 'campaignId берётся из preamble');
        self::assertSame(
            'Кампания по продвижению товаров № 14275771, период 17.04.2026-18.04.2026',
            $entry->campaignName,
            'campaignName = весь preamble-текст как его прислал Ozon',
        );
        self::assertSame('286085455', $entry->parentSku);
        self::assertSame('2527.69', $entry->cost, '1152,19 + 1375,50 = 2527,69 (HALF-UP)');
        self::assertSame(9399, $entry->impressions);
        self::assertSame(468, $entry->clicks);
    }

    public function testFilenameFallbackWhenPreambleMissing(): void
    {
        // Нет preamble — сразу header. campaign_id извлекается из filename маркера.
        $csv = "День;sku;Показы;Клики;spend\n"
            ."17.04.2026;SKU-1;100;5;10,00\n";

        $payload = $this->wrapWithMarker(
            'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            '99999999_17.04.2026-17.04.2026.csv',
            $csv,
        );

        $entries = $this->parser->parse($payload);

        self::assertCount(1, $entries);
        self::assertSame('99999999', $entries[0]->campaignId);
        self::assertSame('Кампания № 99999999', $entries[0]->campaignName, 'fallback по имени при отсутствии preamble');
    }

    public function testAggregatesMultipleSkusIndependently(): void
    {
        $csv = "\xEF\xBB\xBF;Кампания по продвижению товаров № 14275771, период 17.04.2026-17.04.2026\n"
            ."День;sku;Показы;Клики;Расход, ₽, с НДС\n"
            ."17.04.2026;SKU-A;100;5;10,00\n"
            ."17.04.2026;SKU-B;200;10;20,00\n"
            ."17.04.2026;SKU-A;50;2;5,00\n";

        $payload = $this->wrapWithMarker('bb', '14275771_17.04.2026-17.04.2026.csv', $csv);

        $entries = $this->parser->parse($payload);

        self::assertCount(2, $entries);

        $bySku = [];
        foreach ($entries as $e) {
            $bySku[$e->parentSku] = $e;
        }

        self::assertSame('15.00', $bySku['SKU-A']->cost, '10,00 + 5,00 = 15,00');
        self::assertSame(150, $bySku['SKU-A']->impressions);
        self::assertSame(7, $bySku['SKU-A']->clicks);
        self::assertSame('20.00', $bySku['SKU-B']->cost);
    }

    public function testFooterAndEmptySkuSkipped(): void
    {
        $csv = "\xEF\xBB\xBF;Кампания по продвижению товаров № 14275771, период 17.04.2026-17.04.2026\n"
            ."День;sku;Показы;Клики;Расход, ₽, с НДС\n"
            .";SKU-EMPTY-DATE;1;1;1,00\n" // пустая дата + непустой sku: проходит (мы не отсекаем по дате без sku-guard)
            ."17.04.2026;;1;1;1,00\n"       // пустой sku — отсекается
            ."Всего;;9399;468;2527,69\n";   // footer — отсекается

        $payload = $this->wrapWithMarker('bb', '14275771.csv', $csv);

        $entries = $this->parser->parse($payload);

        self::assertCount(1, $entries, 'Footer и пустой sku отсекаются; строка без даты не отсекается');
        self::assertSame('SKU-EMPTY-DATE', $entries[0]->parentSku);
    }

    public function testEmptyCsvReturnsEmptyArray(): void
    {
        $payload = $this->wrapWithMarker('bb', '14275771.csv', '');
        self::assertSame([], $this->parser->parse($payload));
    }

    public function testInvalidMarkerWithoutSeparatorThrows(): void
    {
        $payload = "batch_id=bb\nfilename=x.csv\n"; // нет "\n---\n"

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/маркер.+`---`/u');

        $this->parser->parse($payload);
    }

    public function testPayloadWithoutMarkerDelegatesToJsonParser(): void
    {
        // Не начинается с `batch_id=` → делегирование в JSON-парсер.
        $json = json_encode([
            'rows' => [
                ['campaign_id' => '123', 'campaign_name' => 'Кампания A', 'sku' => 'SKU-1',
                 'spend' => 10.50, 'views' => 100, 'clicks' => 5],
            ],
        ], JSON_THROW_ON_ERROR);

        $entries = $this->parser->parse($json);

        self::assertCount(1, $entries);
        self::assertSame('123', $entries[0]->campaignId);
        self::assertSame('Кампания A', $entries[0]->campaignName);
        self::assertSame('SKU-1', $entries[0]->parentSku);
        self::assertSame('10.50', $entries[0]->cost);
    }

    public function testEmptyJsonPayloadDelegatesAndReturnsEmpty(): void
    {
        // `{}` от DownloadOzonAdReportHandler (парсинг отключён в v1.18).
        self::assertSame([], $this->parser->parse('{}'));
    }

    public function testInvalidJsonThroughDelegationThrows(): void
    {
        // Делегат JSON-парсера кидает \JsonException — не ловим внутри нового парсера.
        $this->expectException(\JsonException::class);
        $this->parser->parse('{not-json');
    }

    public function testCommaDelimiterCsvParsesCorrectly(): void
    {
        // Legacy fallback-формат: `campaign_id` как колонка, `,`-разделитель,
        // без preamble. Этот путь теперь хоть и не основной, но не ломается.
        $csv = "campaign_id,campaign_name,sku,spend,views,clicks\n"
            ."111,Campaign A,SKU-1,10.50,100,5\n";

        $payload = $this->wrapWithMarker('bb', 'legacy.csv', $csv);

        $entries = $this->parser->parse($payload);

        self::assertCount(1, $entries);
        self::assertSame('111', $entries[0]->campaignId);
        self::assertSame('Campaign A', $entries[0]->campaignName);
        self::assertSame('SKU-1', $entries[0]->parentSku);
        self::assertSame('10.50', $entries[0]->cost);
    }

    private function wrapWithMarker(string $batchId, string $filename, string $csv): string
    {
        return sprintf("batch_id=%s\nfilename=%s\n---\n%s", $batchId, $filename, $csv);
    }
}
