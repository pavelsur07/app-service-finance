<?php

declare(strict_types=1);

namespace App\MarketplaceFunnel\Application\DTO;

final readonly class FunnelReport
{
    /** Каналы таблицы воронки и их подписи. */
    public const CHANNELS = ['ozon' => 'Ozon', 'wildberries' => 'WB'];

    /** Источники переходов и их подписи; `search` — часть `total`, не слагаемое. */
    public const SOURCES = ['total' => 'Все переходы', 'search' => 'Из поиска (WB)'];

    /**
     * @param list<FunnelReportRow> $rows
     * @param list<string> $channels каналы компании с данными воронки
     * @param list<string> $sources источники компании с переведёнными днями
     */
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public array $rows,
        public FunnelReportRow $total,
        public array $channels,
        public int $days,
        public int $unavailableDays,
        public string $source = 'total',
        public array $sources = ['total'],
    ) {
    }
}
