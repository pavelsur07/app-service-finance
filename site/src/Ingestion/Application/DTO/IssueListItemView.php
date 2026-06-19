<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

final readonly class IssueListItemView
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $humanDescription,
        public string $createdAt,
    ) {
    }

    /**
     * @return array{id: string, kind: string, human_description: string, created_at: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'human_description' => $this->humanDescription,
            'created_at' => $this->createdAt,
        ];
    }
}
