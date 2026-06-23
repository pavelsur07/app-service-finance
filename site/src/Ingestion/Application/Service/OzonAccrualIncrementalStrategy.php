<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Service;

use App\Ingestion\Application\Action\EnsureOzonAccrualCursorAction;
use App\Ingestion\Application\Command\EnsureOzonAccrualCursorCommand;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Enum\IngestSource;
use Symfony\Component\Clock\ClockInterface;

final readonly class OzonAccrualIncrementalStrategy extends AbstractDailyCursorIncrementalStrategy
{
    public function __construct(
        private EnsureOzonAccrualCursorAction $ensureCursorAction,
        ClockInterface $clock,
    ) {
        parent::__construct($clock);
    }

    public function source(): IngestSource
    {
        return IngestSource::OZON;
    }

    public function resourceType(): string
    {
        return OzonResourceType::ACCRUAL_BY_DAY;
    }

    public function supportsConnection(array $connection): bool
    {
        return IngestSource::OZON->value === (string) $connection['marketplace'];
    }

    public function ensureCursor(string $companyId, string $connectionRef): void
    {
        ($this->ensureCursorAction)(new EnsureOzonAccrualCursorCommand($companyId, $connectionRef));
    }
}
