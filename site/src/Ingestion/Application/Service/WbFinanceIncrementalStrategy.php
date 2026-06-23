<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Service;

use App\Ingestion\Application\Action\EnsureWbFinanceCursorAction;
use App\Ingestion\Application\Command\EnsureWbFinanceCursorCommand;
use App\Ingestion\Application\Source\Wildberries\WbResourceType;
use App\Ingestion\Enum\IngestSource;

final readonly class WbFinanceIncrementalStrategy implements IncrementalResourceStrategyInterface
{
    public function __construct(private EnsureWbFinanceCursorAction $ensureCursorAction)
    {
    }

    public function source(): IngestSource
    {
        return IngestSource::WILDBERRIES;
    }

    public function resourceType(): string
    {
        return WbResourceType::FINANCE_SALES_REPORT_DETAILED;
    }

    public function supportsConnection(array $connection): bool
    {
        return IngestSource::WILDBERRIES->value === (string) $connection['marketplace'];
    }

    public function ensureCursor(string $companyId, string $connectionRef): void
    {
        ($this->ensureCursorAction)(new EnsureWbFinanceCursorCommand($companyId, $connectionRef));
    }

    public function cursorIsDue(string $cursorValue): bool
    {
        $cursorDate = $this->normalizedCursorDate($cursorValue);
        if (null === $cursorDate) {
            return true;
        }

        return new \DateTimeImmutable($cursorDate) <= $this->yesterday();
    }

    private function normalizedCursorDate(string $cursorValue): ?string
    {
        try {
            return (new \DateTimeImmutable($cursorValue))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function yesterday(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('today'))->modify('-1 day')->setTime(0, 0);
    }
}
