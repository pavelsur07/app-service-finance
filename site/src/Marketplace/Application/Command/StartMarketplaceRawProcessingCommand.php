<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Command;

use App\Marketplace\Enum\PipelineTrigger;

/**
 * Команда запуска полного daily processing run для raw-документа.
 *
 * Передаётся в StartMarketplaceRawProcessingAction.
 * Не является Messenger-сообщением — только Application-команда.
 */
final readonly class StartMarketplaceRawProcessingCommand
{
    public function __construct(
        public string $companyId,
        public string $rawDocumentId,
        public PipelineTrigger $trigger,
    ) {}
}
