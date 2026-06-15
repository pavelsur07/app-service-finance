<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

interface TenantOwnedInterface
{
    public function getCompanyId(): string;
}
