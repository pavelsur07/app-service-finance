<?php

declare(strict_types=1);

namespace App\Balance\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\Schema\SchemaExtension;
use Doctrine\DBAL\Schema\Schema;

/**
 * Баланс: ссылки внутри компании — составными ключами `(company_id, …)`, единственность открытия и сторно.
 *
 * Объекты существуют в БД (миграции), но не выводятся из атрибутов сущностей; без этого описания
 * diff схемы предлагает их удалить. Устройство — {@see SchemaExtension}.
 */
final readonly class BalanceSchemaExtension implements SchemaExtension
{
    public function extend(Schema $schema): void
    {
        // Маппинг делает из BalanceCategory::$parent простой ключ по parent_id; в БД ключ составной,
        // по компании (нельзя сослаться на статью чужой компании). Заменяем ключ ORM ключом из миграции.
        $articles = $schema->getTable('balance_articles');
        $articles->dropForeignKey('FK_945D80D8727ACA70');
        $articles->dropIndex('IDX_945D80D8727ACA70');

        $schema->getTable('balance_articles')->addForeignKeyConstraint('balance_articles', ['company_id', 'parent_id'], ['company_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_balance_article_parent');
        $schema->getTable('balance_operation_lines')->addForeignKeyConstraint('balance_accounts', ['company_id', 'account_id'], ['company_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_balance_line_account');
        $schema->getTable('balance_operation_lines')->addForeignKeyConstraint('balance_operations', ['company_id', 'operation_id'], ['company_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_balance_line_operation');
        $schema->getTable('balance_accounts')->addForeignKeyConstraint('balance_articles', ['company_id', 'article_id'], ['company_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_balance_account_article');
        $schema->getTable('balance_account_states')->addForeignKeyConstraint('balance_accounts', ['company_id', 'account_id'], ['company_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_balance_state_account');
        $schema->getTable('balance_operations')->addForeignKeyConstraint('balance_operations', ['company_id', 'original_operation_id'], ['company_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_balance_operation_original');
        $schema->getTable('balance_operations')->addUniqueIndex(['company_id'], 'uniq_balance_operation_opening', ['where' => '((kind)::text = \'opening\'::text)']);
        $schema->getTable('balance_operations')->addUniqueIndex(['company_id', 'original_operation_id'], 'uniq_balance_operation_reversal', ['where' => '((kind)::text = \'reversal\'::text)']);
    }
}
