<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Маппинги начислений Ozon by-day без `type_id` (docs/tasks/ozon-accrual-type-mapping-fix).
 *
 * Маппинги в БД сильнее каталога `OzonAccrualCategory` и применяются, даже если
 * категория `deprecated`, поэтому правки каталога без этой миграции не доходят до
 * разбора. Миграция:
 *  - отключает маппинги ключей `type:29`, `type:45`, `type:76`, `type:77` — их
 *    создал seed по карте `typeIds`, расходящейся со справочником Ozon (29 —
 *    LastMileCourier, а не Logistic); услуги разбираются по коду;
 *  - перенацеливает `code:`/`name:` ключи SellerReturns, Placements, ItemPacking,
 *    TemporaryPlacement на статьи по `description` справочника Ozon.
 *
 * Трогает только строки без ручной правки (`updated_by IS NULL`) и только с
 * ожидаемыми прежними значениями; ручная правка затронутого ключа останавливает
 * миграцию. Каждое изменение пишется в аудит с меткой задачи. Обратима: down()
 * возвращает прежние значения и удаляет свои записи аудита.
 */
final class Version20261018120000 extends AbstractMigration
{
    private const REASON = 'ozon-accrual-type-mapping-fix';

    private const DISABLED_TYPE_KEYS = ['type:29', 'type:45', 'type:76', 'type:77'];

    /**
     * Ключ услуги => [прежние значения, новые значения]:
     * code, label, group, transaction_type, sort_order.
     *
     * @var array<string, array{0: array{string, string, string, string, int}, 1: array{string, string, string, string, int}}>
     */
    private const RETARGETS = [
        'sellerreturns' => [
            ['ozon_partner_return_processing', 'Обработка возвратов, отмен и невыкупов партнёрами', 'Услуги партнёров', 'fee', 500],
            ['ozon_warehouse_export', 'Вывоз товара со склада силами Ozon', 'Услуги FBO', 'storage', 700],
        ],
        'placements' => [
            ['ozon_partner_placement', 'Размещение товара партнёрами', 'Услуги партнёров', 'storage', 550],
            ['ozon_warehouse_placement', 'Размещение товаров на складах', 'Услуги FBO', 'storage', 710],
        ],
        'itempacking' => [
            ['ozon_partner_packaging', 'Упаковка товара партнёрами', 'Услуги партнёров', 'fee', 520],
            ['ozon_warehouse_additional_packaging', 'Дополнительная упаковка на складе Ozon', 'Услуги FBO', 'fee', 730],
        ],
        'temporaryplacement' => [
            ['ozon_temporary_partner_storage', 'Временное размещение товара партнёрами', 'Услуги партнёров', 'storage', 540],
            ['ozon_fbs_return_short_placement', 'Краткосрочное размещение возврата FBS', 'Услуги FBO', 'storage', 740],
        ],
    ];

    private const MAPPING_SNAPSHOT = "jsonb_build_object(
        'canonicalCode', m.canonical_code,
        'canonicalLabel', m.canonical_label,
        'canonicalGroup', m.canonical_group,
        'transactionType', m.transaction_type,
        'defaultDirection', m.default_direction,
        'sortOrder', m.sort_order,
        'known', m.known,
        'status', m.status
    )";

    private const OZON_BY_DAY = "c.source = 'ozon' AND c.resource_type = 'ozon_finance_accrual_by_day'";

    public function getDescription(): string
    {
        return 'Ozon accrual by-day mappings: disable type_id keys, retarget SellerReturns/Placements/ItemPacking/TemporaryPlacement';
    }

    public function up(Schema $schema): void
    {
        $this->abortIfNotPostgres();

        $manualEdits = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ingest_external_category_mappings m
             JOIN ingest_external_categories c ON c.id = m.external_category_id
             WHERE '.self::OZON_BY_DAY.' AND c.normalized_key IN (:keys) AND m.updated_by IS NOT NULL',
            ['keys' => $this->affectedKeys()],
            ['keys' => ArrayParameterType::STRING],
        );
        $this->abortIf(
            $manualEdits > 0,
            sprintf('%d affected Ozon mapping(s) were edited manually; resolve them in the admin before this migration.', $manualEdits),
        );

        foreach (self::DISABLED_TYPE_KEYS as $key) {
            $where = self::OZON_BY_DAY." AND c.normalized_key = :key AND m.status = 'active' AND m.updated_by IS NULL";
            $this->auditThenUpdate(
                $where,
                ['key' => $key],
                "status = 'disabled'",
                [],
                "'status', 'disabled'",
            );
        }

        foreach (self::RETARGETS as $service => [$old, $new]) {
            foreach (['code:', 'name:'] as $prefix) {
                $where = self::OZON_BY_DAY.' AND c.normalized_key = :key AND m.updated_by IS NULL
                    AND m.canonical_code = :oldCode AND m.canonical_label = :oldLabel AND m.canonical_group = :oldGroup
                    AND m.transaction_type = :oldType AND m.sort_order = :oldSort';
                $this->auditThenUpdate(
                    $where,
                    ['key' => $prefix.$service, 'oldCode' => $old[0], 'oldLabel' => $old[1], 'oldGroup' => $old[2], 'oldType' => $old[3], 'oldSort' => $old[4]],
                    'canonical_code = :newCode, canonical_label = :newLabel, canonical_group = :newGroup, transaction_type = :newType, sort_order = :newSort',
                    ['newCode' => $new[0], 'newLabel' => $new[1], 'newGroup' => $new[2], 'newType' => $new[3], 'newSort' => $new[4]],
                    "'canonicalCode', CAST(:newCode AS text), 'canonicalLabel', CAST(:newLabel AS text), 'canonicalGroup', CAST(:newGroup AS text), 'transactionType', CAST(:newType AS text), 'sortOrder', CAST(:newSort AS int)",
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIfNotPostgres();

        foreach (self::DISABLED_TYPE_KEYS as $key) {
            $this->addSql(
                "UPDATE ingest_external_category_mappings AS m
                 SET status = 'active', updated_at = (now() AT TIME ZONE 'Europe/Moscow')
                 FROM ingest_external_categories AS c
                 WHERE c.id = m.external_category_id AND ".self::OZON_BY_DAY." AND c.normalized_key = :key
                   AND m.status = 'disabled' AND m.updated_by IS NULL
                   AND EXISTS (SELECT 1 FROM ingest_external_category_mapping_audit a
                               WHERE a.external_category_id = c.id AND a.company_id IS NULL AND a.new_values->>'reason' = :reason)",
                ['key' => $key, 'reason' => self::REASON],
            );
        }

        foreach (self::RETARGETS as $service => [$old, $new]) {
            foreach (['code:', 'name:'] as $prefix) {
                $this->addSql(
                    "UPDATE ingest_external_category_mappings AS m
                     SET canonical_code = :oldCode, canonical_label = :oldLabel, canonical_group = :oldGroup,
                         transaction_type = :oldType, sort_order = :oldSort, updated_at = (now() AT TIME ZONE 'Europe/Moscow')
                     FROM ingest_external_categories AS c
                     WHERE c.id = m.external_category_id AND ".self::OZON_BY_DAY." AND c.normalized_key = :key
                       AND m.updated_by IS NULL
                       AND m.canonical_code = :newCode AND m.canonical_label = :newLabel AND m.canonical_group = :newGroup
                       AND m.transaction_type = :newType AND m.sort_order = :newSort",
                    [
                        'key' => $prefix.$service,
                        'oldCode' => $old[0], 'oldLabel' => $old[1], 'oldGroup' => $old[2], 'oldType' => $old[3], 'oldSort' => $old[4],
                        'newCode' => $new[0], 'newLabel' => $new[1], 'newGroup' => $new[2], 'newType' => $new[3], 'newSort' => $new[4],
                    ],
                );
            }
        }

        $this->addSql(
            "DELETE FROM ingest_external_category_mapping_audit WHERE company_id IS NULL AND new_values->>'reason' = :reason",
            ['reason' => self::REASON],
        );
    }

    /**
     * @param array<string, string|int> $whereParams
     * @param array<string, string|int> $setParams
     */
    private function auditThenUpdate(string $where, array $whereParams, string $set, array $setParams, string $newValuesPairs): void
    {
        $this->addSql(
            "INSERT INTO ingest_external_category_mapping_audit
                (id, external_category_id, company_id, scope, action, old_values, new_values, updated_by, created_at)
             SELECT gen_random_uuid(), c.id, NULL, 'default', 'update', ".self::MAPPING_SNAPSHOT.',
                    '.self::MAPPING_SNAPSHOT." || jsonb_build_object(".$newValuesPairs.", 'reason', CAST(:reason AS text)),
                    NULL, (now() AT TIME ZONE 'Europe/Moscow')
             FROM ingest_external_category_mappings AS m
             JOIN ingest_external_categories AS c ON c.id = m.external_category_id
             WHERE ".$where,
            $whereParams + $setParams + ['reason' => self::REASON],
        );

        $this->addSql(
            'UPDATE ingest_external_category_mappings AS m
             SET '.$set.", updated_at = (now() AT TIME ZONE 'Europe/Moscow')
             FROM ingest_external_categories AS c
             WHERE c.id = m.external_category_id AND ".$where,
            $whereParams + $setParams,
        );
    }

    /**
     * @return list<string>
     */
    private function affectedKeys(): array
    {
        $keys = self::DISABLED_TYPE_KEYS;
        foreach (array_keys(self::RETARGETS) as $service) {
            $keys[] = 'code:'.$service;
            $keys[] = 'name:'.$service;
        }

        return $keys;
    }

    private function abortIfNotPostgres(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );
    }
}
