<?php

declare(strict_types=1);

namespace App\Finance\Enum;

enum PlPreviewUnplacedReason: string
{
    case CATEGORY_NOT_FOUND = 'category_not_found';
    case NOT_LEAF_INPUT = 'not_leaf_input';
    case NO_CODE = 'no_code';

    public function label(): string
    {
        return match ($this) {
            self::CATEGORY_NOT_FOUND => 'Статья не найдена в компании',
            self::NOT_LEAF_INPUT => 'Статья не является строкой ввода',
            self::NO_CODE => 'У статьи нет кода',
        };
    }
}
