<?php

declare(strict_types=1);

namespace App\Budget\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\Schema\SchemaExtension;
use Doctrine\DBAL\Schema\Schema;

/**
 * Бюджет: ключи на компанию и один утверждённый вариант на год.
 *
 * Объекты создают миграции Version20261104090000 и Version20261104150000; без этого описания diff схемы предлагает их
 * удалить. Устройство — {@see SchemaExtension}.
 */
final readonly class BudgetSchemaExtension implements SchemaExtension
{
    public function extend(Schema $schema): void
    {
        $schema->getTable('budget_versions')->addUniqueIndex(['company_id', 'year'], 'uniq_budget_versions_approved_year', ['where' => '((status)::text = \'approved\'::text)']);
        $schema->getTable('budget_versions')->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_budget_versions_company');
        $schema->getTable('budget_lines')->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_budget_lines_company');
        $schema->getTable('budget_rules')->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_budget_rules_company');
    }
}
