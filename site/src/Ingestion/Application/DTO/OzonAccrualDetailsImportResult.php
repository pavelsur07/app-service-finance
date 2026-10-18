<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

final readonly class OzonAccrualDetailsImportResult
{
    /**
     * @param list<OzonAccrualDetailsTypeResult> $types
     */
    public function __construct(
        public int $rows,
        public ?string $periodFrom,
        public ?string $periodTo,
        public array $types,
        public bool $dryRun,
        public int $skippedRows = 0,
    ) {
    }

    public function withSkippedRows(int $skippedRows): self
    {
        return new self($this->rows, $this->periodFrom, $this->periodTo, $this->types, $this->dryRun, $skippedRows);
    }

    /**
     * @return list<OzonAccrualDetailsTypeResult>
     */
    public function withOutcome(string $outcome): array
    {
        return array_values(array_filter($this->types, static fn (OzonAccrualDetailsTypeResult $type): bool => $outcome === $type->outcome));
    }
}
