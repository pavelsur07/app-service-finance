<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Fixtures;

final class TenantVisibilityRecorder
{
    /**
     * @var list<string>
     */
    private array $visibleProbeIds = [];

    /**
     * @param list<string> $visibleProbeIds
     */
    public function record(array $visibleProbeIds): void
    {
        $this->visibleProbeIds = $visibleProbeIds;
    }

    /**
     * @return list<string>
     */
    public function visibleProbeIds(): array
    {
        return $this->visibleProbeIds;
    }

    public function reset(): void
    {
        $this->visibleProbeIds = [];
    }
}
