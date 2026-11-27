<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261127120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'OneC: onec_organization.export_allowed (существующие — false), onec_package.organization_guids (у принятых раньше — NULL)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE onec_organization ADD export_allowed BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('ALTER TABLE onec_organization ALTER export_allowed DROP DEFAULT');
        $this->addSql('ALTER TABLE onec_package ADD organization_guids JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE onec_package DROP organization_guids');
        $this->addSql('ALTER TABLE onec_organization DROP export_allowed');
    }
}
