<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

/**
 * Строка файла разметки тегов как она записана: значения не проверены и не нормализованы,
 * их разбирает ImportListingTagsAction.
 */
final readonly class ListingTagsFileRow
{
    public function __construct(
        public int $rowNumber,
        public string $listingId,
        public string $tags,
    ) {
    }
}
