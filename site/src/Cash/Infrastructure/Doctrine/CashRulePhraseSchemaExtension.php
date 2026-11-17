<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\Schema\SchemaExtension;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

final readonly class CashRulePhraseSchemaExtension implements SchemaExtension
{
    public function extend(Schema $schema): void
    {
        $table = $schema->createTable('cash_rule_phrase_search');
        foreach (['task_id', 'company_id', 'counterparty_id', 'category_id'] as $column) {
            $table->addColumn($column, Types::GUID);
        }
        $table->addColumn('direction', Types::STRING, ['length' => 10]);
        $table->addColumn('request_key', Types::STRING, ['length' => 64]);
        $table->addColumn('attempt', Types::INTEGER);
        $table->addColumn('status', Types::STRING, ['length' => 16]);
        $table->addColumn('phrase', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('requested_at', Types::DATETIME_IMMUTABLE);
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('task_id')->create());
        $table->addUniqueIndex(['company_id', 'request_key'], 'uniq_cash_rule_phrase_request');
        $table->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_cash_rule_phrase_company');
        $table->addIndex(['company_id', 'requested_at'], 'idx_cash_rule_phrase_company');
    }
}
