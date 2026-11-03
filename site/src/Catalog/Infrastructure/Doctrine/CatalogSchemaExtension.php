<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\Schema\SchemaExtension;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;

/**
 * Каталог: счётчик внутренних артикулов по компании и году (InternalArticleGeneratorDoctrine, DBAL).
 *
 * Объекты существуют в БД (миграции), но не выводятся из атрибутов сущностей; без этого описания
 * diff схемы предлагает их удалить. Устройство — {@see SchemaExtension}.
 */
final readonly class CatalogSchemaExtension implements SchemaExtension
{
    public function extend(Schema $schema): void
    {
        $table = $schema->createTable('product_import_sequences');
        $table->addColumn('company_id', 'guid');
        $table->addColumn('year', 'smallint');
        $table->addColumn('last_seq', 'integer', ['default' => 0]);
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('company_id', 'year')->create());
    }
}
