<?php

declare(strict_types=1);

namespace App\Settlement\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\Schema\SchemaExtension;
use Doctrine\DBAL\Schema\Schema;

final readonly class SettlementSchemaExtension implements SchemaExtension
{
    public function extend(Schema $schema): void
    {
        if (!$schema->hasTable('settlement_documents')) {
            return;
        }
        $references = [
            ['settlement_items', 'document_id', 'settlement_documents'],
            ['settlement_movements', 'document_id', 'settlement_documents'],
            ['settlement_movements', 'item_id', 'settlement_items'],
            ['settlement_allocations', 'document_id', 'settlement_documents'],
            ['settlement_allocations', 'obligation_item_id', 'settlement_items'],
            ['settlement_allocations', 'payment_item_id', 'settlement_items'],
            ['settlement_source_links', 'active_document_id', 'settlement_documents'],
            ['settlement_documents', 'reversed_document_id', 'settlement_documents'],
        ];
        foreach ($references as [$table, $column, $target]) {
            $schema->getTable($table)->addForeignKeyConstraint($target, ['company_id', $column], ['company_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_'.$table.'_'.$column);
        }
        $schema->getTable('settlement_documents')->addUniqueIndex(['company_id', 'reversed_document_id'], 'uniq_settlement_document_reversal');
        $schema->getTable('settlement_items')->addIndex(['company_id', 'counterparty_id', 'amount_currency', 'effective_date'], 'idx_settlement_items_party');
        $schema->getTable('settlement_movements')->addIndex(['company_id', 'item_id', 'effective_date', 'id'], 'idx_settlement_movements_history');
    }
}
