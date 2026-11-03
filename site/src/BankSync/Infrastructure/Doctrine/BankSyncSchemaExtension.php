<?php

declare(strict_types=1);

namespace App\BankSync\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\Schema\SchemaExtension;
use Doctrine\DBAL\Schema\Schema;

/**
 * Банковские выписки: ключи на компанию, подключение и счёт, один счёт банка на счёт ДДС.
 *
 * Объекты существуют в БД (миграции), но не выводятся из атрибутов сущностей; без этого описания
 * diff схемы предлагает их удалить. Устройство — {@see SchemaExtension}.
 */
final readonly class BankSyncSchemaExtension implements SchemaExtension
{
    public function extend(Schema $schema): void
    {
        $schema->getTable('bank_sync_account')->addForeignKeyConstraint('money_account', ['money_account_id'], ['id'], ['onDelete' => 'SET NULL'], 'fk_bank_sync_account_money_account');
        $schema->getTable('bank_sync_account')->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_bank_sync_account_company');
        $schema->getTable('bank_sync_account')->addForeignKeyConstraint('bank_sync_connection', ['connection_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_bank_sync_account_connection');
        $schema->getTable('bank_sync_connection')->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_bank_sync_connection_company');
        $schema->getTable('bank_sync_import_cursor')->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_bank_sync_import_cursor_company');
        $schema->getTable('bank_sync_account')->addUniqueIndex(['company_id', 'money_account_id'], 'uniq_bank_sync_account_money_account', ['where' => '(money_account_id IS NOT NULL)']);
    }
}
