<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Service;

use App\Ingestion\Application\Action\EnsureWbFinanceCursorAction;
use App\Ingestion\Application\Command\EnsureWbFinanceCursorCommand;
use App\Ingestion\Application\Source\Wildberries\WbResourceType;
use App\Ingestion\Enum\IngestSource;
use Symfony\Component\Clock\ClockInterface;

final readonly class WbFinanceIncrementalStrategy extends AbstractDailyCursorIncrementalStrategy
{
    public function __construct(
        private EnsureWbFinanceCursorAction $ensureCursorAction,
        ClockInterface $clock,
    ) {
        parent::__construct($clock);
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
}
