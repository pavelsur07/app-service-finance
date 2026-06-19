<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

final readonly class PaginationMeta
{
    public function __construct(
        public int $page,
        public int $limit,
        public int $total,
        public int $totalPages,
    ) {
    }

    /**
     * @return array{page: int, limit: int, total: int, total_pages: int}
     */
    public function toArray(): array
    {
        return [
            'page' => $this->page,
            'limit' => $this->limit,
            'total' => $this->total,
            'total_pages' => $this->totalPages,
        ];
    }
}
