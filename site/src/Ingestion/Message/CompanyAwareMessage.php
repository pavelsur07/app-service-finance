<?php

declare(strict_types=1);

namespace App\Ingestion\Message;

interface CompanyAwareMessage
{
    public function getCompanyId(): string;
}
