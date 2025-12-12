<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Ramsey\Uuid\Uuid;

final class Version20251212160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add project direction key to P&L daily totals with backfill for default project';
    }

    public function up(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        // ensure required columns exist even if previous migrations were skipped
        $this->addSql('ALTER TABLE documents ADD COLUMN IF NOT EXISTS project_direction_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE document_operations ADD COLUMN IF NOT EXISTS project_direction_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE pl_daily_totals ADD COLUMN IF NOT EXISTS project_direction_id UUID DEFAULT NULL');

        $companies = $this->connection->fetchFirstColumn('SELECT id FROM companies');
        $defaultProjects = [];

        foreach ($companies as $companyId) {
            $projectId = $this->connection->fetchOne(
                'SELECT id FROM project_directions WHERE company_id = :company_id AND name = :name LIMIT 1',
                [
                    'company_id' => $companyId,
                    'name' => 'Основной',
                ],
                [
                    'company_id' => Types::GUID,
                    'name' => Types::STRING,
                ],
            );

            if ($projectId === false || $projectId === null) {
                $projectId = Uuid::uuid4()->toString();

                $this->connection->insert(
                    'project_directions',
                    [
                        'id' => $projectId,
                        'company_id' => $companyId,
                        'name' => 'Основной',
                    ],
                    [
                        'id' => Types::GUID,
                        'company_id' => Types::GUID,
                        'name' => Types::STRING,
                    ],
                );
            }

            $defaultProjects[$companyId] = $projectId;
        }

        foreach ($defaultProjects as $companyId => $projectId) {
            $this->connection->executeStatement(
                'UPDATE documents SET project_direction_id = :project_direction_id WHERE company_id = :company_id AND project_direction_id IS NULL',
                [
                    'project_direction_id' => $projectId,
                    'company_id' => $companyId,
                ],
                [
                    'project_direction_id' => Types::GUID,
                    'company_id' => Types::GUID,
                ],
            );

            $this->connection->executeStatement(
                'UPDATE pl_daily_totals SET project_direction_id = :project_direction_id WHERE company_id = :company_id AND project_direction_id IS NULL',
                [
                    'project_direction_id' => $projectId,
                    'company_id' => $companyId,
                ],
                [
                    'project_direction_id' => Types::GUID,
                    'company_id' => Types::GUID,
                ],
            );
        }

        $this->connection->executeStatement(
            'UPDATE document_operations o SET project_direction_id = d.project_direction_id FROM documents d WHERE o.document_id = d.id AND o.project_direction_id IS NULL',
        );

        $this->addSql('ALTER TABLE documents ALTER COLUMN project_direction_id SET NOT NULL');
        $this->addSql('ALTER TABLE document_operations ALTER COLUMN project_direction_id SET NOT NULL');
        $this->addSql('ALTER TABLE pl_daily_totals ALTER COLUMN project_direction_id SET NOT NULL');

        $this->addSql('ALTER TABLE documents ADD CONSTRAINT fk_documents_project_direction FOREIGN KEY (project_direction_id) REFERENCES project_directions (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE document_operations ADD CONSTRAINT fk_doc_ops_project_direction FOREIGN KEY (project_direction_id) REFERENCES project_directions (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE pl_daily_totals ADD CONSTRAINT fk_pl_daily_project_direction FOREIGN KEY (project_direction_id) REFERENCES project_directions (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE pl_daily_totals DROP CONSTRAINT IF EXISTS uniq_pl_daily_company_cat_date');
        $this->addSql('DROP INDEX IF EXISTS idx_pl_daily_company_cat_date');
        $this->addSql('ALTER TABLE pl_daily_totals ADD CONSTRAINT uniq_pl_daily_company_cat_date UNIQUE (company_id, pl_category_id, date, project_direction_id)');
        $this->addSql('CREATE INDEX idx_pl_daily_company_cat_date ON pl_daily_totals (company_id, pl_category_id, date, project_direction_id)');
    }

    public function down(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        $this->addSql('ALTER TABLE documents DROP CONSTRAINT IF EXISTS fk_documents_project_direction');
        $this->addSql('ALTER TABLE document_operations DROP CONSTRAINT IF EXISTS fk_doc_ops_project_direction');
        $this->addSql('ALTER TABLE pl_daily_totals DROP CONSTRAINT IF EXISTS fk_pl_daily_project_direction');

        $this->addSql('ALTER TABLE pl_daily_totals DROP CONSTRAINT IF EXISTS uniq_pl_daily_company_cat_date');
        $this->addSql('DROP INDEX IF EXISTS idx_pl_daily_company_cat_date');

        if ($schemaManager->introspectTable('pl_daily_totals')->hasColumn('project_direction_id')) {
            $this->addSql('ALTER TABLE pl_daily_totals DROP COLUMN project_direction_id');
        }

        if ($schemaManager->introspectTable('document_operations')->hasColumn('project_direction_id')) {
            $this->addSql('ALTER TABLE document_operations DROP COLUMN project_direction_id');
        }

        if ($schemaManager->introspectTable('documents')->hasColumn('project_direction_id')) {
            $this->addSql('ALTER TABLE documents DROP COLUMN project_direction_id');
        }

        $this->addSql('ALTER TABLE pl_daily_totals ADD CONSTRAINT uniq_pl_daily_company_cat_date UNIQUE (company_id, pl_category_id, date)');
        $this->addSql('CREATE INDEX idx_pl_daily_company_cat_date ON pl_daily_totals (company_id, pl_category_id, date)');
    }
}
