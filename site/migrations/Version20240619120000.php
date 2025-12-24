<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240619120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make telegram bots global, drop company relation and add updated_at column';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('telegram_bots')) {
            return;
        }

        $this->addSql('ALTER TABLE telegram_bots DROP CONSTRAINT IF EXISTS FK_DACD6ED979B1AD6');
        $this->addSql('DROP INDEX IF EXISTS IDX_DACD6ED979B1AD6');
        $this->addSql('ALTER TABLE telegram_bots DROP COLUMN IF EXISTS company_id');

        $this->addSql("ALTER TABLE telegram_bots ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NOW()");
        $this->addSql('UPDATE telegram_bots SET updated_at = created_at WHERE updated_at IS NULL');
        $this->addSql('ALTER TABLE telegram_bots ALTER COLUMN updated_at SET NOT NULL');
        $this->addSql("COMMENT ON COLUMN telegram_bots.updated_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('telegram_bots')) {
            return;
        }

        $this->addSql('ALTER TABLE telegram_bots DROP COLUMN IF EXISTS updated_at');
        $this->addSql('ALTER TABLE telegram_bots ADD company_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_DACD6ED979B1AD6 ON telegram_bots (company_id)');
        $this->addSql('ALTER TABLE telegram_bots ADD CONSTRAINT FK_DACD6ED979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
