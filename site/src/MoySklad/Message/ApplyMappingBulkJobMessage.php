<?php

declare(strict_types=1);

namespace App\MoySklad\Message;

use Webmozart\Assert\Assert;

/** Обработка пакета принятия предложений; состояние пакета — в moysklad_mapping_bulk_jobs. */
final readonly class ApplyMappingBulkJobMessage
{
    public function __construct(public string $companyId, public string $jobId)
    {
        Assert::uuid($companyId);
        Assert::uuid($jobId);
    }
}
