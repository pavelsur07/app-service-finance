<?php

declare(strict_types=1);

namespace App\MarketplacePlProjection\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\Schema\SchemaExtension;
use Doctrine\DBAL\Schema\Schema;

/**
 * Ключ правила `mpp_rule`: в БД — `NULLS NOT DISTINCT` (миграция), из атрибутов сущности не выводится.
 * Устройство — {@see SchemaExtension}.
 */
final readonly class MarketplacePlProjectionSchemaExtension implements SchemaExtension
{
    public function extend(Schema $schema): void
    {
        $schema->getTable('mpp_rule')->addUniqueIndex(['company_id', 'kind', 'channel', 'source_type', 'valid_from'], 'uniq_mpp_rule_key');
    }
}
