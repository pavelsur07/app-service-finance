<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

use App\Marketplace\Enum\PlPreviewColumnKind;

/**
 * Колонка предпросмотра ОПиУ. level — уровень проекта в дереве (1 — корень); отступ по нему рисует
 * экран и xlsx, в label его нет: выгрузка строками отдаёт чистое название.
 */
final readonly class MarketplacePlPreviewColumn
{
    public function __construct(
        public string $key,
        public string $label,
        public PlPreviewColumnKind $kind,
        public int $level = 1,
    ) {
    }

    public function indentedLabel(): string
    {
        return str_repeat('— ', max(0, $this->level - 1)).$this->label;
    }
}
