<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\DTO;

/**
 * Витрина покрытия сервиса за месяц: снимок (null — витрина ещё пуста) и
 * строки месяца — дни месяца, ключ месяца `YYYY-MM` и справочник `ref`.
 */
final readonly class ServiceCoverageMonth
{
    /**
     * @param list<ServiceCoverageMonthCell> $cells
     */
    public function __construct(
        public string $service,
        public string $month,
        public ?ServiceCoverageSnapshotView $snapshot,
        public array $cells,
    ) {
    }
}
