<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

/**
 * Отказ в запросе к WB finance sales-reports: сколько ждать и почему.
 *
 * `sharedCooldown = true` — активен общий cooldown после 429 от WB (Redis);
 * `false` — исчерпан локальный бакет лимитера `limiter.wb_finance`.
 */
final readonly class WbFinanceThrottleDTO
{
    public function __construct(
        public int $waitSeconds,
        public bool $sharedCooldown,
    ) {
    }
}
