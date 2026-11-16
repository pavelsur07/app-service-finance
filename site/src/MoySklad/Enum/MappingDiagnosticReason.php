<?php

declare(strict_types=1);

namespace App\MoySklad\Enum;

enum MappingDiagnosticReason: string
{
    case NO_ARTICLE = 'no_article';
    case NO_NAME = 'no_name';
    case NO_MATCH = 'no_match';
    case DUPLICATE = 'duplicate';
    case ARCHIVED = 'archived';
    case PACKAGE = 'package_review';
    case SIZE = 'size';
    case UNAVAILABLE = 'unavailable';
    case CLEARED = 'cleared';

    public function label(): string
    {
        return match ($this) {
            self::NO_ARTICLE => 'Не указан артикул', self::NO_NAME => 'Не указано название', self::NO_MATCH => 'Нет совпадений',
            self::DUPLICATE => 'Дубли / несколько кандидатов', self::ARCHIVED => 'Кандидаты в архиве', self::PACKAGE => 'Проверить комплектацию',
            self::SIZE => 'Размер требует проверки', self::UNAVAILABLE => 'Источник или позиция недоступны', self::CLEARED => 'Связь снята вручную',
        };
    }
}
