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
        public ?string $detailsMessage = null,
        public ?string $exceptionClass = null,
        public ?string $resourceType = null,
        public ?string $externalId = null,
        public ?string $fetchedAt = null,
    ) {
    }

    /**
     * @return array{id: string, kind: string, human_description: string, created_at: string, details_message: string|null, exception_class: string|null, resource_type: string|null, external_id: string|null, fetched_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'human_description' => $this->humanDescription,
            'created_at' => $this->createdAt,
            'details_message' => $this->detailsMessage,
            'exception_class' => $this->exceptionClass,
            'resource_type' => $this->resourceType,
            'external_id' => $this->externalId,
            'fetched_at' => $this->fetchedAt,
        ];
    }
}
