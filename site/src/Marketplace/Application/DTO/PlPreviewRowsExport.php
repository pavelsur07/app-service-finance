<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

/**
 * Предпросмотр ОПиУ для выгрузки строками: по отчёту на канал и общий отчёт для контроля суммы
 * каналов.
 */
final readonly class PlPreviewRowsExport
{
    /**
     * @param list<MarketplacePlPreviewReport> $channels по одному на канал, по значению канала
     * @param MarketplacePlPreviewReport|null $all все каналы (`marketplace = null`); null, если канал задан явно
     */
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public array $channels,
        public ?MarketplacePlPreviewReport $all,
    ) {
    }
}
