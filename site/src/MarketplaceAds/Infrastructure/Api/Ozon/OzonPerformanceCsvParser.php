<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Infrastructure\Api\Ozon;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\DTO\AdRawEntry;
use App\MarketplaceAds\Infrastructure\Api\Contract\AdRawDataParserInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Парсер rawPayload рекламной статистики Ozon Performance (Task-12-test).
 *
 * Поддерживает **два формата** полезной нагрузки:
 *
 * 1. **CSV с маркером** (новый cron-driven pipeline, Task-11+):
 *    ```
 *    batch_id=<uuid>
 *    filename=<campaignId>_<from>-<to>.csv
 *    ---
 *    <UTF-8 BOM>;Кампания по продвижению товаров № <id>, период ...
 *    День;sku;...
 *    <data rows>
 *    Всего;;...
 *    ```
 *    Маркер добавляется {@see \App\MarketplaceAds\Application\ExtractBatchesToRawDocumentsAction}.
 *    Парсится нативно — читаем CSV-поток через `fgetcsv` над `php://memory`,
 *    срезаем preamble, отсекаем footer «Всего», экранируем `,` как десятичный
 *    сепаратор, агрегируем по (campaign_id, parentSku).
 *
 * 2. **JSON** (legacy Messenger-pipeline до v1.18 + reprocess старых документов):
 *    `{"campaigns":[…]}` или `{"rows":[…]}` — делегируется в
 *    {@see OzonAdRawDataParser}. Старый парсер оставлен без изменений, чтобы
 *    не ломать обработку уже загруженных документов.
 *
 * Селектор формата: наличие префикса `batch_id=` в начале `raw_payload`. CSV
 * Ozon'а начинается либо с BOM, либо с `;` — с `batch_id=` никогда, поэтому
 * ложное срабатывание исключено без дополнительных эвристик.
 *
 * CSV-хелперы (stripPreamble / iterateCsvAssocRows / isFooterOrEmptyRow /
 * pickColumn / normalizeDecimal) вынесены сюда из приватных методов
 * {@see OzonAdClient}: там они обслуживают старый event-driven Messenger-путь
 * (CSV → JSON → `raw_payload`), здесь — новый путь (CSV напрямую в
 * `raw_payload` через маркер). Деупликация после выпиливания старого pipeline
 * (Task-13+).
 */
final class OzonPerformanceCsvParser implements AdRawDataParserInterface
{
    private const PAYLOAD_MARKER_PREFIX = 'batch_id=';
    private const PAYLOAD_SEPARATOR = "\n---\n";

    /** Точность bcmath-агрегации cost — избегает кумулятивной ошибки округления. */
    private const AGGREGATION_SCALE = 8;

    /** Финальная точность cost — HALF-UP-округление применяется один раз к агрегату. */
    private const FINAL_SCALE = 2;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly OzonAdRawDataParser $jsonFallbackParser,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function supports(string $marketplace): bool
    {
        return MarketplaceType::OZON->value === $marketplace;
    }

    public function parse(string $rawPayload): array
    {
        if (!str_starts_with($rawPayload, self::PAYLOAD_MARKER_PREFIX)) {
            // Нет маркера → это JSON старого pipeline'а (или '{}' от DownloadOzonAdReportHandler
            // с отключённым парсингом). Делегируем нетронутому JSON-парсеру.
            return $this->jsonFallbackParser->parse($rawPayload);
        }

        [$markerFilename, $csv] = $this->splitMarker($rawPayload);
        $filenameCampaignId = $this->extractCampaignIdFromFilename($markerFilename);

        $preamble = $this->stripPreamble($csv);
        $campaignId = '' !== $preamble['campaign_id']
            ? $preamble['campaign_id']
            : $filenameCampaignId;
        $campaignName = '' !== $preamble['campaign_name']
            ? $preamble['campaign_name']
            : ('' === $campaignId ? '' : 'Кампания № '.$campaignId);

        /** @var array<string, array{campaignId: string, campaignName: string, parentSku: string, cost: string, impressions: int, clicks: int}> $aggregated */
        $aggregated = [];
        $dataRowsSeen = 0;
        $headerSample = '';
        $firstDataSample = '';

        foreach ($this->iterateCsvAssocRows($preamble['csv']) as $row) {
            if (0 === $dataRowsSeen) {
                $headerSample = implode(';', array_keys($row));
                $firstDataSample = implode(';', array_values($row));
            }
            ++$dataRowsSeen;

            $sku = (string) ($row['sku'] ?? $row['ozon_sku'] ?? '');
            $rawDate = $this->findDateField($row);

            if ($this->isFooterOrEmptyRow($sku, $rawDate)) {
                continue;
            }

            // campaign_id: preamble/filename (новый формат без колонки) либо
            // колонка `campaign_id`/`id` (fallback-формат — например, legacy
            // one-file-per-company CSV, см. ozon_single_day_legacy.csv фикстуру).
            $rowCampaignId = '' !== $campaignId
                ? $campaignId
                : (string) ($row['campaign_id'] ?? $row['id'] ?? '');

            if ('' === $rowCampaignId || '' === $sku) {
                continue;
            }

            $rowCampaignName = '' !== $campaignName
                ? $campaignName
                : (string) ($row['campaign_name'] ?? '');

            $cost = number_format(
                (float) $this->normalizeDecimal($this->pickColumn($row, ['spend', 'cost', 'расход, ₽, с ндс', 'расход'])),
                self::AGGREGATION_SCALE,
                '.',
                '',
            );
            $impressions = (int) $this->pickColumn($row, ['views', 'impressions', 'показы']);
            $clicks = (int) $this->pickColumn($row, ['clicks', 'клики']);
            $key = $rowCampaignId.'|'.$sku;

            if (!isset($aggregated[$key])) {
                $aggregated[$key] = [
                    'campaignId' => $rowCampaignId,
                    'campaignName' => $rowCampaignName,
                    'parentSku' => $sku,
                    'cost' => $cost,
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                ];

                continue;
            }

            $aggregated[$key]['cost'] = bcadd($aggregated[$key]['cost'], $cost, self::AGGREGATION_SCALE);
            $aggregated[$key]['impressions'] += $impressions;
            $aggregated[$key]['clicks'] += $clicks;
        }

        $entries = array_map(
            static fn (array $r): AdRawEntry => new AdRawEntry(
                campaignId: $r['campaignId'],
                campaignName: $r['campaignName'],
                parentSku: $r['parentSku'],
                // HALF-UP через +0.005 применяется единожды к агрегату — cost
                // никогда не отрицателен у Ozon Performance.
                cost: bcadd($r['cost'], '0.005', self::FINAL_SCALE),
                impressions: $r['impressions'],
                clicks: $r['clicks'],
            ),
            array_values($aggregated),
        );

        if ($dataRowsSeen > 0 && [] === $entries) {
            $this->logger->warning('Ozon Performance CSV: all data rows filtered out', [
                'dataRowsSeen' => $dataRowsSeen,
                'headerSample' => mb_substr($headerSample, 0, 200, 'UTF-8'),
                'firstDataSample' => mb_substr($firstDataSample, 0, 200, 'UTF-8'),
                'filename' => $markerFilename,
                'preambleCampaignId' => $preamble['campaign_id'],
            ]);
        }

        return $entries;
    }

    /**
     * Разделяет payload на `filename` (из маркера) и чистый CSV.
     *
     * @return array{0: string, 1: string} [filename, csv]
     */
    private function splitMarker(string $rawPayload): array
    {
        $sepPos = strpos($rawPayload, self::PAYLOAD_SEPARATOR);
        if (false === $sepPos) {
            throw new \RuntimeException('Ozon Performance CSV payload: маркер `---` не найден');
        }

        $header = substr($rawPayload, 0, $sepPos);
        $csv = substr($rawPayload, $sepPos + strlen(self::PAYLOAD_SEPARATOR));

        $filename = '';
        foreach (explode("\n", $header) as $line) {
            if (str_starts_with($line, 'filename=')) {
                $filename = substr($line, strlen('filename='));
                break;
            }
        }

        return [$filename, $csv];
    }

    /**
     * `<campaignId>_<from>-<to>.csv` → `<campaignId>`. Если формат имени не
     * совпадает — '', тогда парсер полагается на campaign_id из preamble/колонки.
     */
    private function extractCampaignIdFromFilename(string $filename): string
    {
        if (1 === preg_match('/^(\d+)_/', $filename, $m)) {
            return $m[1];
        }

        return '';
    }

    /**
     * Итератор `fgetcsv` над `php://memory` — поддержка RFC 4180 с `""`-escape,
     * автодетект разделителя (`;` если встречается в header'е, иначе `,`),
     * lowercase header-ключей через `mb_strtolower` (для «Дата» / «день» и пр.).
     *
     * @return \Generator<int, array<string, string>>
     */
    private function iterateCsvAssocRows(string $csv): \Generator
    {
        $csv = ltrim($csv, "\xEF\xBB\xBF");
        if ('' === trim($csv)) {
            return;
        }

        $firstNewline = strpos($csv, "\n");
        $headerLine = false === $firstNewline ? $csv : substr($csv, 0, $firstNewline);
        $delimiter = str_contains($headerLine, ';') ? ';' : ',';

        $fp = fopen('php://memory', 'r+b');
        if (false === $fp) {
            throw new \RuntimeException('Ozon Performance CSV: не удалось открыть in-memory поток');
        }

        try {
            fwrite($fp, $csv);
            rewind($fp);

            $headerRow = fgetcsv($fp, 0, $delimiter, '"', '');
            if (false === $headerRow) {
                return;
            }
            $header = array_map(
                static fn ($c): string => mb_strtolower(trim((string) $c), 'UTF-8'),
                $headerRow,
            );

            while (false !== ($cols = fgetcsv($fp, 0, $delimiter, '"', ''))) {
                if ([null] === $cols) {
                    continue;
                }

                $row = [];
                foreach ($header as $i => $name) {
                    $row[$name] = (string) ($cols[$i] ?? '');
                }

                yield $row;
            }
        } finally {
            fclose($fp);
        }
    }

    /**
     * Отрезает preamble Ozon: `;Кампания по продвижению товаров № N, период …`.
     * Извлекает `campaign_id` по регулярке `№\s*(\d+)` и возвращает
     * полный preamble-текст как `campaign_name` (это то, что пользователь видит
     * в Ozon Performance как название кампании — лучше, чем искусственное
     * «Кампания № X»).
     *
     * Если preamble не распознан — CSV возвращается без изменений, чтобы
     * legacy-формат с заголовком в первой строке продолжал работать.
     *
     * @return array{csv: string, campaign_id: string, campaign_name: string}
     */
    private function stripPreamble(string $csv): array
    {
        $csv = ltrim($csv, "\xEF\xBB\xBF");
        if ('' === $csv) {
            return ['csv' => $csv, 'campaign_id' => '', 'campaign_name' => ''];
        }

        $firstNewline = strpos($csv, "\n");
        $firstLine = false === $firstNewline ? $csv : substr($csv, 0, $firstNewline);
        $firstLineTrimmed = rtrim($firstLine, "\r");

        $isPreamble = str_contains($firstLineTrimmed, 'Кампания по продвижению')
            || str_starts_with($firstLineTrimmed, ';')
            || str_starts_with($firstLineTrimmed, ',');

        if (!$isPreamble) {
            return ['csv' => $csv, 'campaign_id' => '', 'campaign_name' => ''];
        }

        $campaignId = '';
        if (1 === preg_match('/№\s*(\d+)/u', $firstLineTrimmed, $matches)) {
            $campaignId = $matches[1];
        }

        // Срезаем ведущий разделитель (`;` или `,`) — текст preamble-ячейки.
        $campaignName = ltrim($firstLineTrimmed, ";,");
        $campaignName = trim($campaignName);

        $rest = false === $firstNewline ? '' : substr($csv, $firstNewline + 1);

        return [
            'csv' => $rest,
            'campaign_id' => $campaignId,
            'campaign_name' => $campaignName,
        ];
    }

    /**
     * @param array<string, string> $row
     */
    private function findDateField(array $row): string
    {
        foreach (['date', 'day', 'дата', 'день'] as $key) {
            if (isset($row[$key])) {
                $value = trim($row[$key]);
                if ('' !== $value) {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * Footer Ozon: «Всего;;...» либо пустой sku. Не-footer строки с пустым sku
     * всё равно пропускаются (нечего мапить на listing).
     */
    private function isFooterOrEmptyRow(string $sku, string $rawDate): bool
    {
        if ('' === $sku) {
            return true;
        }

        $dateCell = mb_strtolower(trim($rawDate), 'UTF-8');

        return 'всего' === $dateCell || 'total' === $dateCell;
    }

    /**
     * @param array<string, string> $row
     * @param list<string>          $keys
     */
    private function pickColumn(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && '' !== trim($row[$key])) {
                return $row[$key];
            }
        }

        return '0';
    }

    private function normalizeDecimal(string $raw): string
    {
        $v = trim(str_replace(',', '.', $raw));

        return '' === $v ? '0' : $v;
    }
}
