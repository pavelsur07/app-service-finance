<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Service;

use App\Ingestion\Application\Action\EnsureOzonAccrualCursorAction;
use App\Ingestion\Application\Command\EnsureOzonAccrualCursorCommand;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Enum\IngestSource;

final readonly class OzonAccrualIncrementalStrategy implements IncrementalResourceStrategyInterface
{
    public function __construct(private EnsureOzonAccrualCursorAction $ensureCursorAction)
    {
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
