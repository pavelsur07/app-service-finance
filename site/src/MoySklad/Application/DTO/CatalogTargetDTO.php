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
        /** @var list<string>|null */
        public ?array $barcodes = null,
    ) {
    }

    /** @return list<string> */
    public function getPieceQuantities(): array
    {
        return \App\MoySklad\Domain\AutomaticListingMappingPolicy::quantities($this->name);
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
            'name' => $this->name, 'code' => $this->code, 'article' => $this->article, 'characteristics' => $this->characteristics, 'barcodes' => $this->barcodes];
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        $reason = (bool) $row['archived'] ? 'archived' : ((bool) $row['parent_archived'] ? 'parent_archived' : null);
        if ('product' === $row['type'] && ((int) $row['variants_count'] > 0 || (bool) $row['has_variants'])) {
            $reason ??= 'requires_variant';
        }
        /** @var list<array{id:string,name:string,value:string}> $characteristics */
        $characteristics = json_decode((string) $row['characteristics'], true, 512, \JSON_THROW_ON_ERROR);

        return new self((string) $row['type'], (string) $row['external_id'], null === $row['parent_external_id'] ? null : (string) $row['parent_external_id'],
            (string) $row['name'], null === $row['code'] ? null : (string) $row['code'], null === $row['article'] ? null : (string) $row['article'], $characteristics, null === $reason, $reason, isset($row['barcodes']) ? json_decode((string) $row['barcodes'], true, 512, \JSON_THROW_ON_ERROR) : null);
    }
}
