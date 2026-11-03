<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Schema;

use Doctrine\DBAL\Schema\Schema;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Объекты схемы, которые существуют в БД (их создают миграции), но не выводятся из атрибутов
 * сущностей: таблицы под DBAL, внешние ключи на строковые ссылки (`string $companyId`),
 * составные ключи по компании, частичные индексы и индексы по выражению.
 *
 * Модуль описывает свои объекты в `{Module}/Infrastructure/Doctrine/*SchemaExtension`, и
 * сгенерированная из маппинга схема совпадает с БД, накатанной миграциями: diff не предлагает
 * их удалить. Источник истины — миграции; описание здесь повторяет их один в один.
 * Сверку держит `tests/Integration/Shared/Doctrine/SchemaInSyncWithMigrationsTest`.
 *
 * Индекс по выражению DBAL интроспектирует без выражения: только обычные колонки и `WHERE`.
 * Так его и описывать; полное определение — в миграции.
 * Текст `WHERE` сравнивается строкой с `pg_get_expr()` — писать его в нормализованном виде Postgres.
 */
#[AutoconfigureTag(self::TAG)]
interface SchemaExtension
{
    public const TAG = 'app.doctrine_schema_extension';

    public function extend(Schema $schema): void;
}
