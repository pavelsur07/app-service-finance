<?php

declare(strict_types=1);

namespace App\MoySklad\Application\DTO;

final readonly class MappingDiagnosticPage
{
    public int $perPage;

    /** @param list<MappingDiagnosticRow> $rows */
    public function __construct(public ?MappingDiagnosticRun $latestRun, public ?MappingDiagnosticRun $completedRun, public bool $stale, public array $rows, public int $page, public int $totalItems)
    {
        $this->perPage = 25;
    }
}
