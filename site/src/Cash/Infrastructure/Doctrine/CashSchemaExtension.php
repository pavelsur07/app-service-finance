<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\Schema\SchemaExtension;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

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
        $observation = $schema->createTable('cash_auto_rule_observation');
        $observation->addColumn('company_id', Types::GUID);
        $observation->addColumn('started_at', Types::DATETIME_IMMUTABLE);
        $observation->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('company_id')->create());

        $conflicts = $schema->createTable('cash_auto_rule_category_conflict');
        $conflicts->addColumn('company_id', Types::GUID);
        $conflicts->addColumn('rule_id', Types::GUID);
        $conflicts->addColumn('revision', Types::INTEGER);
        $conflicts->addColumn('transaction_id', Types::GUID);
        $conflicts->addColumn('observed_at', Types::DATETIME_IMMUTABLE);
        $conflicts->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('company_id', 'rule_id', 'revision', 'transaction_id')->create());
        $conflicts->addIndex(['company_id', 'rule_id', 'observed_at'], 'idx_cash_rule_conflict_period');

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
