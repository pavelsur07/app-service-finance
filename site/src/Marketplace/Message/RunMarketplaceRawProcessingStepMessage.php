<?php

declare(strict_types=1);

namespace App\Marketplace\Message;

/**
 * Сообщение для асинхронного выполнения одного шага daily processing run.
 *
 * Отправляется из StartMarketplaceRawProcessingHandler для каждого PENDING шага.
 * Обрабатывается RunMarketplaceRawProcessingStepHandler в worker-контексте.
 *
 * Только scalar ID — worker-safe.
 */
final readonly class RunMarketplaceRawProcessingStepMessage
{
    public function __construct(
        public string $companyId,        // scalar UUID — worker-safe
        public string $processingRunId,  // scalar UUID — worker-safe
        public string $stepRunId,        // scalar UUID — worker-safe
    ) {}
}
