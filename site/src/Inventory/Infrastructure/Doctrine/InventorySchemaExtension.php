<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\MicrosecondDateTimeImmutableType;
use App\Shared\Infrastructure\Doctrine\Schema\SchemaExtension;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;

/**
 * Склад: хранение времени в `timestamp(6)`.
 *
 * Миграции создали эти колонки как timestamp(6), маппинг — datetime_immutable (пишет до секунды).
 * Описываем хранение как в БД; конвертация в PHP по-прежнему из маппинга. Устройство — {@see SchemaExtension}.
 */
final readonly class InventorySchemaExtension implements SchemaExtension
{
    private const MICROSECOND_COLUMNS = [
        'inventory_locations' => ['created_at', 'updated_at'],
        'inventory_raw_snapshots' => ['fetched_at', 'processed_at', 'created_at'],
        'inventory_snapshot_sessions' => ['started_at', 'completed_at', 'created_at', 'updated_at'],
        'inventory_stock_snapshots' => ['snapshot_at', 'created_at'],
    ];

    public function extend(Schema $schema): void
    {
        foreach (self::MICROSECOND_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $schema->getTable($table)->getColumn($column)->setType(Type::getType(MicrosecondDateTimeImmutableType::NAME));
            }
        }
    }
}
