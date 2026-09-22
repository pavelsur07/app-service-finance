<?php

declare(strict_types=1);

namespace App\MoySklad\Application\DTO;

/** @template T */
final readonly class ParsedPage
{
    /** @param list<T> $rows */
    public function __construct(
        public int $size,
        public int $limit,
        public int $offset,
        public array $rows,
    ) {
    }
}
