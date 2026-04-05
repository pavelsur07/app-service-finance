<?php

declare(strict_types=1);

namespace App\Marketplace\Message;

/**
 * Сообщение для асинхронного запуска daily processing run.
 *
 * Отправляется из StartMarketplaceRawProcessingAction после создания Run и StepRuns.
 * Обрабатывается StartMarketplaceRawProcessingHandler в worker-контексте.
 *
 * Только scalar ID — worker-safe.
 */
final readonly class StartMarketplaceRawProcessingMessage
{
    public function __construct(
        public string $companyId,       // scalar UUID — worker-safe
        public string $processingRunId, // scalar UUID — worker-safe
    ) {}
}
