<?php

declare(strict_types=1);

namespace App\MoySklad\Enum;

enum AutomaticMappingDecisionStatus: string
{
    case READY = 'ready';
    case NO_ARTICLE = 'no_article';
    case NO_MATCH = 'no_match';
    case AMBIGUOUS = 'ambiguous';
    case TOO_MANY_CANDIDATES = 'too_many_candidates';
    case VARIANT_REQUIRED = 'variant_required';
    case SIZE_RULE_REQUIRED = 'size_rule_required';
    case SIZE_UNKNOWN = 'size_unknown';
    case SIZE_MISMATCH = 'size_mismatch';
    case SIZE_UNVERIFIABLE = 'size_unverifiable';
    case TARGET_UNAVAILABLE = 'target_unavailable';
    case EXISTING_MAPPING = 'existing_mapping';
    case CLEARED_MAPPING = 'cleared_mapping';
    case LISTING_UNAVAILABLE = 'listing_unavailable';
    case CATALOG_NOT_READY = 'catalog_not_ready';

    public function label(): string
    {
        return match ($this) {
            self::READY => 'Однозначное соответствие',
            self::NO_ARTICLE => 'Не указан артикул продавца',
            self::NO_MATCH => 'Совпадений артикула нет',
            self::AMBIGUOUS => 'Подходят несколько позиций',
            self::TOO_MANY_CANDIDATES => 'Слишком много совпадений: требуется ручной выбор',
            self::VARIANT_REQUIRED => 'Нужно выбрать конкретную модификацию',
            self::SIZE_RULE_REQUIRED => 'Выберите характеристику размера МойСклад',
            self::SIZE_UNKNOWN => 'Для выбора модификации не хватает размера листинга',
            self::SIZE_MISMATCH => 'Размеры не совпадают',
            self::SIZE_UNVERIFIABLE => 'Размер нельзя однозначно проверить',
            self::TARGET_UNAVAILABLE => 'Позиция или её родитель недоступны',
            self::EXISTING_MAPPING => 'Связь уже существует и не будет заменена',
            self::CLEARED_MAPPING => 'Связь снята вручную и не будет восстановлена',
            self::LISTING_UNAVAILABLE => 'Листинг неактивен или недоступен',
            self::CATALOG_NOT_READY => 'Подключите МойСклад и загрузите товары и модификации',
        };
    }
}
