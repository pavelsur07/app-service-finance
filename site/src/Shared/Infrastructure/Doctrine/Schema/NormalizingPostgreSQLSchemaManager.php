<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Schema;

use App\Shared\Infrastructure\Doctrine\MicrosecondDateTimeImmutableType;
use App\Shared\Infrastructure\Doctrine\UtcMillisecondDateTimeImmutableType;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use Doctrine\DBAL\Schema\PostgreSQLSchemaManager;
use Doctrine\DBAL\Types\JsonType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

/**
 * Интроспекция PostgreSQL без ложных расхождений со схемой из маппинга. Чинит три места DBAL 4:
 *
 * - `timestamp(6)` и `timestamp(3)` читаются как `datetime` без точности, и сравнение всегда
 *   предлагает «изменить» колонки типов `datetime_immutable_us` / `datetime_immutable_utc_ms`.
 *   Точность из БД возвращает колонке её тип.
 * - `DEFAULT now()` сравнивается строкой с `CURRENT_TIMESTAMP` маппинга, хотя в PostgreSQL это одно и
 *   то же. Приводим к `CurrentTimestamp` — так default из маппинга отдаёт ORM.
 * - `jsonb` читается как `json` с устаревшей platform-опцией `jsonb` (deprecation dbal#6939).
 *   Отдаём тип `jsonb`, которым такие колонки объявлены в маппинге.
 *
 * Остальное поведение интроспекции не меняется. Подключён через {@see NormalizingSchemaManagerFactory}.
 */
final class NormalizingPostgreSQLSchemaManager extends PostgreSQLSchemaManager
{
    private const TYPE_BY_PRECISION = [
        'timestamp(6) without time zone' => MicrosecondDateTimeImmutableType::NAME,
        'timestamp(3) without time zone' => UtcMillisecondDateTimeImmutableType::NAME,
    ];

    /** @param array<string, mixed> $tableColumn */
    protected function _getPortableTableColumnDefinition(array $tableColumn): Column
    {
        $column = parent::_getPortableTableColumnDefinition($tableColumn);

        $completeType = array_change_key_case($tableColumn, \CASE_LOWER)['complete_type'] ?? null;
        $typeName = \is_string($completeType) ? (self::TYPE_BY_PRECISION[$completeType] ?? null) : null;

        if (null !== $typeName) {
            $column->setType(Type::getType($typeName));
        }

        if (Type::getType($column->getTypeName()) instanceof JsonType && true === ($column->getPlatformOptions()['jsonb'] ?? false)) {
            $column->setType(Type::getType(Types::JSONB));
            $column->setPlatformOptions([]);
        }

        if ('now()' === $column->getDefault()) {
            $column->setDefault(new CurrentTimestamp());
        }

        return $column;
    }
}
