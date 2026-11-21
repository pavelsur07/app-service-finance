<?php

declare(strict_types=1);

namespace App\MoySklad\Message;

use Webmozart\Assert\Assert;

/** Отмена пакета: снять связи, которые пакет создал и которые с тех пор не меняли. */
final readonly class UndoMappingBulkJobMessage
{
    public function __construct(public string $companyId, public string $jobId)
    {
        Assert::uuid($companyId);
        Assert::uuid($jobId);
    }
}
