<?php

declare(strict_types=1);

namespace App\OneC\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\Schema\SchemaExtension;
use Doctrine\DBAL\Schema\Schema;

final readonly class OneCSchemaExtension implements SchemaExtension
{
    public function extend(Schema $schema): void
    {
        $schema->getTable('onec_connection')->addForeignKeyConstraint('user', ['owner_id'], ['id'], ['onDelete' => 'CASCADE'], 'onec_connection_owner_id_fkey');
        $schema->getTable('onec_organization')->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'SET NULL'], 'onec_organization_company_id_fkey');
    }
}
