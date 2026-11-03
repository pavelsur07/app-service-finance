<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\Schema\SchemaExtension;
use Doctrine\DBAL\Schema\Schema;

/**
 * ДДС: ключи на компанию, транзакцию и ЦФО у сущностей со строковыми ссылками.
 *
 * Объекты существуют в БД (миграции), но не выводятся из атрибутов сущностей; без этого описания
 * diff схемы предлагает их удалить. Устройство — {@see SchemaExtension}.
 */
final readonly class CashSchemaExtension implements SchemaExtension
{
    public function extend(Schema $schema): void
    {
        $schema->getTable('cash_category_suggestion')->addForeignKeyConstraint('cash_transaction', ['transaction_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_cash_category_suggestion_transaction');
        $schema->getTable('cash_category_suggestion')->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_cash_category_suggestion_company');
        $schema->getTable('cash_transaction')->addForeignKeyConstraint('financial_responsibility_centers', ['responsibility_center_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_cash_transaction_responsibility_center');
        $schema->getTable('cash_transaction')->addIndex(['responsibility_center_id'], 'idx_cash_transaction_responsibility_center');
        $schema->getTable('cash_transaction_auto_rule')->addForeignKeyConstraint('financial_responsibility_centers', ['responsibility_center_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_ctar_responsibility_center');
        $schema->getTable('cash_transaction_auto_rule')->addIndex(['responsibility_center_id'], 'idx_ctar_responsibility_center');
        $schema->getTable('cash_categorization_task')->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_cash_categorization_task_company');
        $schema->getTable('cash_ai_categorization_setting')->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_cash_ai_categorization_setting_company');
    }
}
