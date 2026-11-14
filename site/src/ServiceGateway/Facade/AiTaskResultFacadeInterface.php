<?php

declare(strict_types=1);

namespace App\ServiceGateway\Facade;

interface AiTaskResultFacadeInterface
{
    /** Imports an available result and acknowledges only after its transaction commits. */
    public function refreshTask(string $companyId, string $taskId): bool;
}
