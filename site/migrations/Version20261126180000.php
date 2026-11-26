<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Части пакетов 1С: время снимка snapshot_at (у принятых раньше — NULL)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE onec_package ADD snapshot_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE onec_package DROP snapshot_at');
    }
}
