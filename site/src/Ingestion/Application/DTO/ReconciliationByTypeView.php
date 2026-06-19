<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

final readonly class ReconciliationByTypeView
{
    public function __construct(
        public string $type,
        public string $typeLabel,
        public int $canonAmountMinor,
        public int $txCount,
    ) {
    }

    /**
     * @return array{type: string, type_label: string, canon_amount_minor: int, tx_count: int}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'type_label' => $this->typeLabel,
            'canon_amount_minor' => $this->canonAmountMinor,
            'tx_count' => $this->txCount,
        ];
    }
}
