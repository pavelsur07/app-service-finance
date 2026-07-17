<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Tests\Support\Kernel\IntegrationTestCase;

final class ResponsibilityCenterFactSchemaTest extends IntegrationTestCase
{
    /** @var array<string, string> */
    private const FACT_CONSTRAINTS = [
        'cash_transaction' => 'fk_cash_transaction_responsibility_center',
        'documents' => 'fk_documents_responsibility_center',
        'document_operations' => 'fk_doc_ops_responsibility_center',
        'pl_daily_totals' => 'fk_pl_daily_responsibility_center',
    ];

    /** @var array<string, string> */
    private const FACT_INDEXES = [
        'cash_transaction' => 'idx_cash_transaction_responsibility_center',
        'documents' => 'idx_documents_responsibility_center',
        'document_operations' => 'idx_doc_ops_responsibility_center',
        'pl_daily_totals' => 'idx_pl_daily_responsibility_center',
    ];

    public function testFactColumnsAndRestrictiveForeignKeysExist(): void
    {
        foreach (self::FACT_CONSTRAINTS as $table => $constraint) {
            $column = $this->connection->fetchAssociative(
                <<<'SQL'
                    SELECT data_type, is_nullable, column_default
                    FROM information_schema.columns
                    WHERE table_schema = 'public'
                      AND table_name = :table
                      AND column_name = 'responsibility_center_id'
                    SQL,
                ['table' => $table],
            );

            self::assertIsArray($column, sprintf('Missing %s.responsibility_center_id.', $table));
            self::assertSame('uuid', $column['data_type']);
            self::assertSame('YES', $column['is_nullable']);
            self::assertNull($column['column_default']);
            self::assertSame(
                0,
                (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE responsibility_center_id IS NOT NULL', $table)),
            );

            $foreignKey = $this->connection->fetchAssociative(
                <<<'SQL'
                    SELECT constraint_def.confdeltype,
                           constraint_def.condeferrable::int AS condeferrable,
                           constraint_def.condeferred::int AS condeferred,
                           pg_get_constraintdef(constraint_def.oid) AS definition
                    FROM pg_constraint constraint_def
                    INNER JOIN pg_class table_def ON table_def.oid = constraint_def.conrelid
                    INNER JOIN pg_namespace schema_def ON schema_def.oid = table_def.relnamespace
                    WHERE schema_def.nspname = 'public'
                      AND table_def.relname = :table
                      AND constraint_def.conname = :constraint
                    SQL,
                ['table' => $table, 'constraint' => $constraint],
            );

            self::assertIsArray($foreignKey, sprintf('Missing constraint %s.', $constraint));
            self::assertSame('r', $foreignKey['confdeltype']);
            self::assertSame(0, (int) $foreignKey['condeferrable']);
            self::assertSame(0, (int) $foreignKey['condeferred']);
            self::assertStringContainsString(
                'FOREIGN KEY (responsibility_center_id) REFERENCES financial_responsibility_centers(id) ON DELETE RESTRICT',
                $foreignKey['definition'],
            );
        }
    }

    public function testFactIndexesExistAndPnlUniquenessIsUnchanged(): void
    {
        foreach (self::FACT_INDEXES as $table => $index) {
            $definition = (string) $this->connection->fetchOne(
                "SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = :table AND indexname = :index",
                ['table' => $table, 'index' => $index],
            );

            self::assertStringContainsString('(responsibility_center_id)', $definition);
        }

        $legacyDefinition = $this->indexDefinition('uniq_pl_daily_company_cat_date');
        self::assertStringContainsString('UNIQUE INDEX', $legacyDefinition);
        self::assertStringNotContainsString('NULLS NOT DISTINCT', $legacyDefinition);
        self::assertStringContainsString(
            '(company_id, pl_category_id, date, project_direction_id)',
            $legacyDefinition,
        );

        self::assertNull(
            $this->connection->fetchOne("SELECT to_regclass('public.uniq_pl_daily_company_cat_date_project_center')"),
        );
    }

    private function indexDefinition(string $index): string
    {
        return (string) $this->connection->fetchOne(
            "SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'pl_daily_totals' AND indexname = :index",
            ['index' => $index],
        );
    }
}
