<?php

declare(strict_types=1);

namespace App\Marketplace\Message;

/**
 * Запускает асинхронную обработку одного шага (sales/returns/costs)
 * одного MarketplaceRawDocument в daily pipeline.
 * Отправляется диспетчером, обрабатывается Worker'ом.
 */
final readonly class ProcessRawDocumentStepMessage
{
    public function __construct(
        public string $rawDocumentId,
        public string $step,
        public string $companyId,
    ) {
    }
}
