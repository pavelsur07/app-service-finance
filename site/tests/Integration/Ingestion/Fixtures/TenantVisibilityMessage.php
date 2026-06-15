<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Fixtures;

use App\Ingestion\Message\CompanyAwareMessage;

final readonly class TenantVisibilityMessage implements CompanyAwareMessage
{
    public function __construct(private string $companyId)
    {
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }
}
