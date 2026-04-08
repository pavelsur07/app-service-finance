<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Command;

/**
 * Worker/CLI-safe command for raw document processing.
 *
 * Allowed kinds: 'sales'|'returns'|'costs'.
 */
final class ProcessMarketplaceRawDocumentCommand
{
    public function __construct(
        public readonly string $companyId,
        public readonly string $rawDocId,
        public readonly string $kind,
        public readonly bool $forceReprocess = false,
    ) {}
}

