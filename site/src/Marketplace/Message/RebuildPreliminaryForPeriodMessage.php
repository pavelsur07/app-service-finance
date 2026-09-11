<?php

declare(strict_types=1);

namespace App\Marketplace\Message;

/**
 * Асинхронное сообщение для пересборки предварительного ОПиУ за период.
 * Только scalar — безопасно для Worker/сериализации.
 *
 * `stages` — какие этапы пересобирать. null означает «все», и так ходит ночной
 * пересбор текущего месяца. Исторический период приходит с точным списком:
 * он попал в очередь из-за одного конкретного предварительно закрытого этапа, и
 * трогать соседний нельзя — он может быть открыт человеком ради правок.
 */
final readonly class RebuildPreliminaryForPeriodMessage
{
    public function __construct(
        public string $companyId,
        public string $marketplace,   // MarketplaceType::value
        public int $year,
        public int $month,
        public string $actorUserId,
        /** @var list<string>|null */
        public ?array $stages = null,
    ) {
    }
}
