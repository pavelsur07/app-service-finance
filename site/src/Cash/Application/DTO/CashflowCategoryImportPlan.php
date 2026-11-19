<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

/**
 * Что загрузка дерева статей ДДС из файла запишет в компанию.
 *
 * @phpstan-type Values array{name: string, description: ?string, status: string, sort: int, parentId: ?string, operationType: ?string, allowPlDocument: bool, plCategoryId: ?string, code: ?string, flowKind: string}
 * @phpstan-type Item array{id: string, new: bool, path: string, values: Values, changes: list<array{label: string, from: string, to: string}>}
 */
final readonly class CashflowCategoryImportPlan
{
    /**
     * @param list<string> $errors файл отклонён целиком, если список не пуст
     * @param list<Item> $items создаваемые и изменяемые статьи, родитель раньше потомка
     * @param int $unchangedCount статьи файла, совпавшие с компанией
     * @param list<string> $newIds ID для статей файла без id, в порядке обхода: форма возвращает их при применении
     */
    public function __construct(
        public array $errors,
        public array $items,
        public int $unchangedCount,
        public array $newIds,
        public string $fingerprint,
    ) {
    }

    /** @return list<Item> */
    public function created(): array
    {
        return array_values(array_filter($this->items, static fn (array $item): bool => $item['new']));
    }

    /** @return list<Item> */
    public function updated(): array
    {
        return array_values(array_filter($this->items, static fn (array $item): bool => !$item['new']));
    }
}
