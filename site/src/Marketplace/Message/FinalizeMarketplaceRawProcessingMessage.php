<?php

declare(strict_types=1);

namespace App\Marketplace\Message;

/**
 * Сообщение для асинхронной финализации daily processing run.
 *
 * Отправляется из RunMarketplaceRawProcessingStepHandler после каждого
 * терминального завершения шага (COMPLETED или FAILED без retry).
 *
 * Только scalar ID — worker-safe.
 */
final readonly class FinalizeMarketplaceRawProcessingMessage
{
    public function __construct(
        public string $companyId,       // scalar UUID — worker-safe
        public string $processingRunId, // scalar UUID — worker-safe
    ) {}
}
