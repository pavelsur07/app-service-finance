<?php

declare(strict_types=1);

namespace App\MoySklad\Application\DTO;

final readonly class CatalogTargetDTO
{
    /** @param list<array{id:string,name:string,value:string}> $characteristics */
    public function __construct(
        public string $type, public string $externalId, public ?string $parentExternalId,
        public string $name, public ?string $code, public ?string $article,
        public array $characteristics, public bool $selectable, public ?string $reason = null,
    ) {
    }

    public function getValue(): string
    {
        return $this->type.':'.$this->externalId;
    }

    public function getTypeLabel(): string
    {
        return 'variant' === $this->type ? 'Модификация' : 'Товар';
    }

    /** @return array<string, mixed> */
    public function toSnapshot(): array
    {
        return ['type' => $this->type, 'externalId' => $this->externalId, 'parentExternalId' => $this->parentExternalId,
            'name' => $this->name, 'code' => $this->code, 'article' => $this->article, 'characteristics' => $this->characteristics];
    }
}
