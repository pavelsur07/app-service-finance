<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Accepted DBAL schema-comparison drift: PostgreSQL introspects nextval() as
 * autoincrement=true/default=null, unlike this generated non-PK ORM column.
 * Never apply a generated diff to companies.public_id or remove its mapping
 * default: the DB default is required for legacy INSERTs omitting this column.
 * Keep the explicit mapping default so auto-diffs cannot silently DROP DEFAULT.
 */
final class Version20260913090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Assign permanent numeric public company IDs without changing UUID identities';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE companies_public_id_seq AS INTEGER START WITH 100000 MINVALUE 100000 NO CYCLE');
        $this->addSql("ALTER TABLE companies ADD public_id INT DEFAULT nextval('companies_public_id_seq') NOT NULL");
        $this->addSql('ALTER SEQUENCE companies_public_id_seq OWNED BY companies.public_id');
        $this->addSql('CREATE UNIQUE INDEX uniq_companies_public_id ON companies (public_id)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Public company IDs must never be reassigned or reused; use a forward migration.');
    }
}
