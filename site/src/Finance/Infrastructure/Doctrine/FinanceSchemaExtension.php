<?php

declare(strict_types=1);

namespace App\Finance\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\Schema\SchemaExtension;
use Doctrine\DBAL\Schema\Schema;

/**
 * ОПиУ: ключи на ЦФО, уникальность дневных итогов с учётом ЦФО.
 *
 * Объекты существуют в БД (миграции), но не выводятся из атрибутов сущностей; без этого описания
 * diff схемы предлагает их удалить. Устройство — {@see SchemaExtension}.
 */
final readonly class FinanceSchemaExtension implements SchemaExtension
{
    public function extend(Schema $schema): void
    {
        $schema->getTable('document_operations')->addForeignKeyConstraint('financial_responsibility_centers', ['responsibility_center_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_doc_ops_responsibility_center');
        $schema->getTable('document_operations')->addIndex(['responsibility_center_id'], 'idx_doc_ops_responsibility_center');
        $schema->getTable('pl_daily_totals')->addForeignKeyConstraint('financial_responsibility_centers', ['responsibility_center_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_pl_daily_responsibility_center');
        $schema->getTable('pl_daily_totals')->addIndex(['responsibility_center_id'], 'idx_pl_daily_responsibility_center');
        $schema->getTable('pl_daily_totals')->addUniqueIndex(['company_id', 'date', 'project_direction_id'], 'uniq_pl_daily_uncat_date_project_center', ['where' => '(pl_category_id IS NULL)']);
        $schema->getTable('pl_daily_totals')->addUniqueIndex(['company_id', 'pl_category_id', 'date', 'project_direction_id'], 'uniq_pl_daily_company_cat_date_project_center', ['where' => '(pl_category_id IS NOT NULL)']);
        $schema->getTable('documents')->addForeignKeyConstraint('financial_responsibility_centers', ['responsibility_center_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_documents_responsibility_center');
        $schema->getTable('documents')->addIndex(['responsibility_center_id'], 'idx_documents_responsibility_center');
        // В БД оба uniq_pl_daily_* содержат ещё COALESCE(responsibility_center_id, '00000000-…'): NULL ЦФО — один ключ.
    }
}
