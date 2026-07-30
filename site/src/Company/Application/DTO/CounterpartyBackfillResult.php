<?php

declare(strict_types=1);

namespace App\Company\Application\DTO;

final class CounterpartyBackfillResult
{
    public int $processed = 0;
    public int $updated = 0;
    public int $unchanged = 0;

    /**
     * ID строк, у которых название не нормализуется — их разбирают руками.
     *
     * @var list<string>
     */
    public array $skipped = [];
}
