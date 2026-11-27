<?php

declare(strict_types=1);

namespace App\OneC\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\Schema\SchemaExtension;
use Doctrine\DBAL\Schema\Schema;

final readonly class OneCSchemaExtension implements SchemaExtension
{
    public function extend(Schema $schema): void
    {
        $connection = $schema->getTable('onec_connection');
        $connection->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'CASCADE'], 'onec_connection_company_id_fkey');
        $connection->addForeignKeyConstraint('user', ['created_by_user_id'], ['id'], ['onDelete' => 'SET NULL'], 'onec_connection_created_by_user_id_fkey');
    }
}
