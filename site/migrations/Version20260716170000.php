<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260716170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add company responsibility centers, stable general-project codes, and allowed project pairs';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $ambiguousCompanies = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*)
            FROM (
                SELECT company_id
                FROM project_directions
                WHERE LOWER(BTRIM(name)) IN ('общий', 'основной', 'общие операции')
                GROUP BY company_id
                HAVING COUNT(*) > 1
            ) ambiguous
            SQL);

        $this->abortIf(
            $ambiguousCompanies > 0,
            sprintf('Found %d company/companies with ambiguous general-project candidates.', $ambiguousCompanies),
        );

        $this->addSql('ALTER TABLE project_directions ADD system_code VARCHAR(64) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            WITH candidates AS (
                SELECT company_id, MIN(id::text)::uuid AS project_id
                FROM project_directions
                WHERE LOWER(BTRIM(name)) IN ('общий', 'основной', 'общие операции')
                GROUP BY company_id
                HAVING COUNT(*) = 1
            )
            UPDATE project_directions project
            SET system_code = 'PROJECT_GENERAL'
            FROM candidates candidate
            WHERE project.id = candidate.project_id
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO project_directions (id, company_id, name, parent_id, sort, system_code)
            SELECT gen_random_uuid(), company.id, 'Общий', NULL, 0, 'PROJECT_GENERAL'
            FROM companies company
            WHERE NOT EXISTS (
                SELECT 1
                FROM project_directions project
                WHERE project.company_id = company.id
                  AND project.system_code = 'PROJECT_GENERAL'
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_project_direction_company_system_code ON project_directions (company_id, system_code)');

        $this->addSql('CREATE TABLE financial_responsibility_centers (id UUID NOT NULL, company_id UUID NOT NULL, code VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, sort INT DEFAULT 0 NOT NULL, status VARCHAR(16) NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_frc_company_code ON financial_responsibility_centers (company_id, code)');
        $this->addSql('CREATE INDEX idx_frc_company_status_sort ON financial_responsibility_centers (company_id, status, sort)');
        $this->addSql('ALTER TABLE financial_responsibility_centers ADD CONSTRAINT fk_frc_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT DEFERRABLE INITIALLY DEFERRED');
        $this->addSql("COMMENT ON COLUMN financial_responsibility_centers.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN financial_responsibility_centers.updated_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql(<<<'SQL'
            INSERT INTO financial_responsibility_centers (
                id, company_id, code, name, sort, status, version, created_at, updated_at
            )
            SELECT gen_random_uuid(), company.id, 'CFO_GENERAL', 'Общий', 0, 'active', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM companies company
            SQL);

        $this->addSql('CREATE TABLE financial_responsibility_center_projects (id UUID NOT NULL, company_id UUID NOT NULL, project_direction_id UUID NOT NULL, responsibility_center_id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_9074EB0E85D43DF4 ON financial_responsibility_center_projects (project_direction_id)');
        $this->addSql('CREATE INDEX IDX_9074EB0ED63B41A6 ON financial_responsibility_center_projects (responsibility_center_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_frc_project_pair ON financial_responsibility_center_projects (project_direction_id, responsibility_center_id)');
        $this->addSql('CREATE INDEX idx_frc_project_company_center ON financial_responsibility_center_projects (company_id, responsibility_center_id)');
        $this->addSql('CREATE INDEX idx_frc_project_company_project ON financial_responsibility_center_projects (company_id, project_direction_id)');
        $this->addSql('ALTER TABLE financial_responsibility_center_projects ADD CONSTRAINT fk_frc_project_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT DEFERRABLE INITIALLY DEFERRED');
        $this->addSql('ALTER TABLE financial_responsibility_center_projects ADD CONSTRAINT fk_frc_project_direction FOREIGN KEY (project_direction_id) REFERENCES project_directions (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE financial_responsibility_center_projects ADD CONSTRAINT fk_frc_project_center FOREIGN KEY (responsibility_center_id) REFERENCES financial_responsibility_centers (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql("COMMENT ON COLUMN financial_responsibility_center_projects.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql(<<<'SQL'
            INSERT INTO financial_responsibility_center_projects (
                id, company_id, project_direction_id, responsibility_center_id, created_at
            )
            SELECT gen_random_uuid(), company.id, project.id, center.id, CURRENT_TIMESTAMP
            FROM companies company
            INNER JOIN project_directions project
                ON project.company_id = company.id
               AND project.system_code = 'PROJECT_GENERAL'
            INNER JOIN financial_responsibility_centers center
                ON center.company_id = company.id
               AND center.code = 'CFO_GENERAL'
            SQL);
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM companies company
                    LEFT JOIN project_directions project
                        ON project.company_id = company.id
                       AND project.system_code = 'PROJECT_GENERAL'
                    LEFT JOIN financial_responsibility_centers center
                        ON center.company_id = company.id
                       AND center.code = 'CFO_GENERAL'
                    LEFT JOIN financial_responsibility_center_projects pair
                        ON pair.company_id = company.id
                       AND pair.project_direction_id = project.id
                       AND pair.responsibility_center_id = center.id
                    GROUP BY company.id
                    HAVING COUNT(DISTINCT project.id) <> 1
                        OR COUNT(DISTINCT center.id) <> 1
                        OR COUNT(DISTINCT pair.id) <> 1
                ) THEN
                    RAISE EXCEPTION 'Stage 7.2 system-pair invariant failed';
                END IF;
            END $$
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'The migration creates system projects that cannot be distinguished safely from pre-existing projects during rollback.',
        );
    }
}
