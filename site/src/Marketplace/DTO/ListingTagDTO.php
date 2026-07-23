<?php

declare(strict_types=1);

namespace App\Marketplace\DTO;

final readonly class ListingTagDTO
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }

    /**
     * @return array{id: string, name: string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
}
