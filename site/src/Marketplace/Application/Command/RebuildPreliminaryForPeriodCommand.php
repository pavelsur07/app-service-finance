<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Command;

/**
 * Команда ежедневной пересборки предварительного ОПиУ за период.
 * Worker-safe: только scalar-поля.
 */
final class RebuildPreliminaryForPeriodCommand
{
    /**
     * @param list<string>|null $stages null rebuilds all stages
     */
    public function __construct(
        public readonly string $companyId,
        public readonly string $marketplace,   // MarketplaceType::value
        public readonly int $year,
        public readonly int $month,
        public readonly string $actorUserId,   // системный UUID (cron) или UUID реального пользователя (ручной запуск)
        public readonly ?array $stages = null,
    ) {
    }
}
