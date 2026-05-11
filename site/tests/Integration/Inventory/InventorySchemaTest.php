<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use App\Tests\Support\Kernel\IntegrationTestCase;

final class InventorySchemaTest extends IntegrationTestCase
{
    public function testInventoryTablesColumnsAndIndexesExistWithExpectedDefinitions(): void
    {
        $conn = $this->em->getConnection();

        self::assertSame('inventory_locations', $conn->fetchOne("SELECT to_regclass('public.inventory_locations')"));
        self::assertSame('inventory_snapshot_sessions', $conn->fetchOne("SELECT to_regclass('public.inventory_snapshot_sessions')"));
        self::assertSame('inventory_raw_snapshots', $conn->fetchOne("SELECT to_regclass('public.inventory_raw_snapshots')"));
        self::assertSame('inventory_stock_snapshots', $conn->fetchOne("SELECT to_regclass('public.inventory_stock_snapshots')"));

        $locationIndex = (string) $conn->fetchOne("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND indexname = 'uniq_inventory_location_company_external'");
        self::assertStringContainsString('UNIQUE INDEX uniq_inventory_location_company_external', $locationIndex);

        $rawUnprocessedIndex = (string) $conn->fetchOne("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND indexname = 'idx_inventory_raw_unprocessed'");
        self::assertStringContainsString('WHERE (is_processed = false)', $rawUnprocessedIndex);

        $activeSessionsIndex = (string) $conn->fetchOne("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND indexname = 'idx_inventory_sessions_active'");
        self::assertStringContainsString('WHERE', $activeSessionsIndex);
        self::assertStringContainsString('pending', $activeSessionsIndex);
        self::assertStringContainsString('in_progress', $activeSessionsIndex);

        $stockUniqueIndex = (string) $conn->fetchOne("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND indexname = 'uniq_inventory_stock_snapshot_day_item'");
        self::assertStringContainsString('NULLS NOT DISTINCT', $stockUniqueIndex);
        self::assertStringContainsString('(company_id, snapshot_date, source, source_sku, fulfillment_type, location_id, status)', $stockUniqueIndex);

        $quantityDef = $conn->fetchAssociative(<<<'SQL'
            SELECT data_type, numeric_precision, numeric_scale
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = 'inventory_stock_snapshots'
              AND column_name = 'quantity'
        SQL);
        self::assertIsArray($quantityDef);
        self::assertSame('numeric', $quantityDef['data_type']);
        self::assertSame(14, (int) $quantityDef['numeric_precision']);
        self::assertSame(3, (int) $quantityDef['numeric_scale']);
        $reservedQuantityDef = $conn->fetchAssociative(<<<'SQL'
            SELECT data_type, numeric_precision, numeric_scale
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = 'inventory_stock_snapshots'
              AND column_name = 'reserved_quantity'
        SQL);
        self::assertIsArray($reservedQuantityDef);
        self::assertSame('numeric', $reservedQuantityDef['data_type']);
        self::assertSame(14, (int) $reservedQuantityDef['numeric_precision']);
        self::assertSame(3, (int) $reservedQuantityDef['numeric_scale']);

        $mappingStatusDef = $conn->fetchAssociative(<<<'SQL'
            SELECT data_type, character_maximum_length, column_default
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = 'inventory_stock_snapshots'
              AND column_name = 'mapping_status'
        SQL);
        self::assertIsArray($mappingStatusDef);
        self::assertSame('character varying', $mappingStatusDef['data_type']);
        self::assertSame(50, (int) $mappingStatusDef['character_maximum_length']);
        self::assertStringContainsString('unmapped', (string) $mappingStatusDef['column_default']);

        $companySourceSkuIndex = (string) $conn->fetchOne("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND indexname = 'idx_inventory_stock_company_source_sku'");
        self::assertStringContainsString('(company_id, source_sku)', $companySourceSkuIndex);

        $companyMappingStatusIndex = (string) $conn->fetchOne("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND indexname = 'idx_inventory_stock_company_mapping_status'");
        self::assertStringContainsString('(company_id, mapping_status)', $companyMappingStatusIndex);

        $requestParamsType = $conn->fetchOne("SELECT udt_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'inventory_raw_snapshots' AND column_name = 'request_params'");
        $responseBodyType = $conn->fetchOne("SELECT udt_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'inventory_raw_snapshots' AND column_name = 'response_body'");
        $metadataType = $conn->fetchOne("SELECT udt_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'inventory_locations' AND column_name = 'metadata'");
        self::assertSame('jsonb', $requestParamsType);
        self::assertSame('jsonb', $responseBodyType);
        self::assertSame('jsonb', $metadataType);

        $snapshotDateType = $conn->fetchOne("SELECT data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'inventory_stock_snapshots' AND column_name = 'snapshot_date'");
        self::assertSame('date', $snapshotDateType);
    }
}
