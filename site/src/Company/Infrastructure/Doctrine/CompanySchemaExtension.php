<?php

declare(strict_types=1);

namespace App\Company\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\Schema\SchemaExtension;
use Doctrine\DBAL\Schema\Schema;

/**
 * Компания: ключи ЦФО и налоговых профилей, уникальность ролей и профилей, последовательность публичного номера.
 *
 * Объекты существуют в БД (миграции), но не выводятся из атрибутов сущностей; без этого описания
 * diff схемы предлагает их удалить. Устройство — {@see SchemaExtension}.
 */
final readonly class CompanySchemaExtension implements SchemaExtension
{
    public function extend(Schema $schema): void
    {
        // Компании нумеруются от 100000 (Company::$publicId, default nextval).
        $schema->createSequence('companies_public_id_seq', 1, 100000);

        $schema->getTable('company_tax_profiles')->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_company_tax_profiles_company');
        $schema->getTable('company_tax_profiles')->addUniqueIndex(['company_id', 'valid_from'], 'uniq_company_tax_profiles_company_valid_from');
        $schema->getTable('financial_responsibility_centers')->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'RESTRICT', 'deferrable' => true, 'deferred' => true], 'fk_frc_company');
        $schema->getTable('financial_responsibility_center_projects')->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'RESTRICT', 'deferrable' => true, 'deferred' => true], 'fk_frc_project_company');
        $schema->getTable('company_role')->addUniqueIndex(['company_id'], 'uniq_company_role_company_name', ['where' => '(company_id IS NOT NULL)']);
        // В БД выражение: (company_id, lower(name)) WHERE company_id IS NOT NULL.
    }
}
