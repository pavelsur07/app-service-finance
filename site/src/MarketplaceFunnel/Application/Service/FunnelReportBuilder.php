<?php

declare(strict_types=1);

namespace App\MarketplaceFunnel\Application\Service;

use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceFunnel\Application\DTO\FunnelReport;
use App\MarketplaceFunnel\Application\DTO\FunnelReportRow;
use App\MarketplaceFunnel\Domain\FunnelRow;
use App\MarketplaceFunnel\Infrastructure\Query\FunnelReportQuery;

/**
 * Экран «Воронка» (R9): товар × ступени за период, названия из листингов, поиск, сортировка, итог.
 * Товар без листинга остаётся строкой с идентификатором маркетплейса.
 */
final readonly class FunnelReportBuilder
{
    public const MAX_DAYS = 366;

    public function __construct(
        private FunnelReportQuery $query,
        private MarketplaceFacade $marketplace,
    ) {
    }

    public function build(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, ?string $channel, string $search, string $sort, bool $desc): FunnelReport
    {
        if (null !== $channel && !isset(FunnelReport::CHANNELS[$channel])) {
            throw new \InvalidArgumentException('Неизвестный маркетплейс.');
        }
        if ($from > $to) {
            throw new \InvalidArgumentException('Начало периода позже конца.');
        }
        if ((int) $from->diff($to)->days + 1 > self::MAX_DAYS) {
            throw new \InvalidArgumentException(sprintf('Период — не больше %d дней.', self::MAX_DAYS));
        }

        $data = $this->query->bySku($companyId, $from, $to, $channel);
        $titles = $this->titles($companyId, $data);
        $needle = mb_strtolower(trim($search));

        $rows = [];
        $total = array_fill_keys(FunnelRow::COLUMNS, null);
        foreach ($data as $item) {
            $meta = $titles[$item['channel'].'|'.$item['sku']] ?? null;
            if ('' !== $needle && !str_contains(mb_strtolower(implode(' ', [$item['sku'], $meta['title'] ?? '', $meta['supplierSku'] ?? ''])), $needle)) {
                continue;
            }
            $rows[] = new FunnelReportRow($item['channel'], $item['sku'], $meta['title'] ?? null, $meta['supplierSku'] ?? null, $item['values']);
            foreach ($item['values'] as $column => $value) {
                if (null !== $value) {
                    $total[$column] = ($total[$column] ?? 0) + $value;
                }
            }
        }

        $sort = \in_array($sort, FunnelRow::COLUMNS, true) ? $sort : 'orders';
        usort($rows, static fn (FunnelReportRow $a, FunnelReportRow $b): int => ($desc ? -1 : 1) * (($a->values[$sort] ?? -1) <=> ($b->values[$sort] ?? -1)) ?: strcmp($a->channel.$a->sku, $b->channel.$b->sku));

        $coverage = $this->query->coverage($companyId, $from, $to, $channel);

        return new FunnelReport($from, $to, $rows, new FunnelReportRow('', 'Итого', null, null, $total), $coverage['channels'], $coverage['days'], $coverage['unavailableDays']);
    }

    /**
     * @param list<array{channel: string, sku: string, values: array<string, int|null>}> $data
     *
     * @return array<string, array{title: ?string, supplierSku: ?string}> «канал|sku» → подписи первого листинга товара
     */
    private function titles(string $companyId, array $data): array
    {
        $skus = [];
        foreach ($data as $item) {
            $skus[$item['channel']][] = $item['sku'];
        }

        $listingIds = [];
        foreach ($skus as $channel => $list) {
            foreach ($this->marketplace->findListingsByMarketplaceSkus($companyId, $channel, $list) as $sku => $listings) {
                $listingIds[$channel.'|'.$sku] = $listings[0]['id'];
            }
        }
        $meta = $this->marketplace->getListingsMetaByIds($companyId, array_values($listingIds));

        $titles = [];
        foreach ($listingIds as $key => $listingId) {
            if (isset($meta[$listingId])) {
                $titles[$key] = ['title' => $meta[$listingId]->title, 'supplierSku' => $meta[$listingId]->supplierSku];
            }
        }

        return $titles;
    }
}
