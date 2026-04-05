<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Command;

/**
 * Команда финализации daily processing run.
 *
 * Передаётся в FinalizeMarketplaceRawProcessingAction.
 * Не является Messenger-сообщением — только Application-команда.
 */
final readonly class FinalizeMarketplaceRawProcessingCommand
{
    public function __construct(
        public string $companyId,
        public string $processingRunId,
    ) {}
}
