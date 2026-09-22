<?php

declare(strict_types=1);

namespace App\MoySklad\Exception;

/** Safe failure category: never include the response body or transport error. */
final class StockSyncException extends \RuntimeException
{
    public function __construct(public readonly string $category, public readonly ?int $retryAfterMs = null, public readonly ?string $runId = null)
    {
        parent::__construct('MoySklad stock sync failed: '.$category);
    }
}
