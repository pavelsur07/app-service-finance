<?php

declare(strict_types=1);

namespace App\MarketplaceFunnel\Application\DTO;

final readonly class FunnelReport
{
    /** Каналы таблицы воронки и их подписи. */
    public const CHANNELS = ['ozon' => 'Ozon', 'wildberries' => 'WB'];

    /**
     * @param list<FunnelReportRow> $rows
     * @param list<string> $channels каналы компании с данными воронки
     */
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public array $rows,
        public FunnelReportRow $total,
        public array $channels,
        public int $days,
        public int $unavailableDays,
    ) {
    }
}
