<?php

declare(strict_types=1);

namespace App\Deals\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\Schema\SchemaExtension;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;

/**
 * Сделки: счётчик номеров сделок компании (DealNumberGenerator, DBAL).
 *
 * Объекты существуют в БД (миграции), но не выводятся из атрибутов сущностей; без этого описания
 * diff схемы предлагает их удалить. Устройство — {@see SchemaExtension}.
 */
final readonly class DealsSchemaExtension implements SchemaExtension
{
    public function extend(Schema $schema): void
    {
        $table = $schema->createTable('deals_deal_sequence');
        $table->addColumn('company_id', 'guid');
        $table->addColumn('last_number', 'integer');
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('company_id')->create());
        $schema->getTable('deals_deal_sequence')->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_deals_deal_sequence_company');
    }
}
