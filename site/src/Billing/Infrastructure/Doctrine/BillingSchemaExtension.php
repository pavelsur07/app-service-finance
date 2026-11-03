<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\Schema\SchemaExtension;
use Doctrine\DBAL\Schema\Schema;

/**
 * Биллинг: ключи на компанию и на заменивший платёж.
 *
 * Объекты существуют в БД (миграции), но не выводятся из атрибутов сущностей; без этого описания
 * diff схемы предлагает их удалить. Устройство — {@see SchemaExtension}.
 */
final readonly class BillingSchemaExtension implements SchemaExtension
{
    public function extend(Schema $schema): void
    {
        $schema->getTable('billing_payments')->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_billing_payments_company');
        $schema->getTable('billing_payments')->addForeignKeyConstraint('billing_payments', ['superseded_by_payment_id'], ['id'], ['onDelete' => 'SET NULL'], 'fk_billing_payments_superseded_by');
        $schema->getTable('billing_expiry_reminders')->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_billing_expiry_reminders_company');
        $schema->getTable('billing_company_subscriptions')->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_billing_company_subscriptions_company');
        $schema->getTable('billing_checkouts')->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_billing_checkouts_company');
    }
}
