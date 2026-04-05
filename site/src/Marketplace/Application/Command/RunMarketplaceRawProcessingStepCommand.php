<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Command;

/**
 * Команда выполнения одного шага daily processing run.
 *
 * Передаётся в RunMarketplaceRawProcessingStepAction.
 * Не является Messenger-сообщением — только Application-команда.
 */
final readonly class RunMarketplaceRawProcessingStepCommand
{
    public function __construct(
        public string $companyId,
        public string $processingRunId,
        public string $stepRunId,
    ) {}
}
