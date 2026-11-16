<?php

declare(strict_types=1);

namespace App\MoySklad\Infrastructure\Query;

use App\Marketplace\DTO\ExternalMappingSourceVersion;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MoySklad\Application\DTO\AutomaticMappingGuard;
use App\MoySklad\Application\DTO\MappingDiagnosticPage;
use App\MoySklad\Application\DTO\MappingDiagnosticRow;
use App\MoySklad\Application\DTO\MappingDiagnosticRun;
use App\MoySklad\Enum\AutomaticMappingDecisionStatus;
use App\MoySklad\Enum\MappingDiagnosticReason;
use Doctrine\DBAL\Connection;
use Webmozart\Assert\Assert;

final readonly class MappingDiagnosticsQuery
{
    public const RUN_FIELDS = 'id, company_id, connection_id, marketplace, size_characteristic_id, rule_version, settings_fingerprint, input_fingerprint, status, requested_at, finished_at, after_listing_id, processed, total, counts, error_reason';

    public function __construct(private Connection $db, private MarketplaceFacade $marketplace, private MappingSettingsQuery $settings)
    {
    }

    /** Version hashes data only; matching business rules stay in the shared evaluator. */
    public function inputVersion(string $companyId, string $connectionId, string $marketplace, ?string $sizeCharacteristicId): ExternalMappingSourceVersion
    {
        $source = $this->marketplace->getExternalMappingSourceVersion($companyId, $marketplace);
        $catalog = $this->db->fetchOne(<<<'SQL'
            SELECT md5(COALESCE(string_agg(hash, '' ORDER BY kind, external_id), '')) FROM (
                SELECT 'p' AS kind, external_id, md5(jsonb_build_array(external_id, name, code, article, barcodes, archived, variants_count)::text) AS hash
                FROM moysklad_products WHERE company_id=:company AND connection_id=:connection
                UNION ALL
                SELECT 'v' AS kind, external_id, md5(jsonb_build_array(external_id, product_external_id, name, code, article, barcodes, archived, characteristics)::text) AS hash
                FROM moysklad_variants WHERE company_id=:company AND connection_id=:connection
            ) catalog
            SQL, ['company' => $companyId, 'connection' => $connectionId]);
        $state = $this->db->fetchAssociative(<<<'SQL'
            SELECT
                (SELECT md5(COALESCE(string_agg(id::text, '' ORDER BY id), '')) FROM moysklad_listing_mapping_revisions
                 WHERE company_id=:company AND connection_id=:connection AND listing_snapshot->>'marketplace'=:marketplace) AS mappings,
                (SELECT md5(COALESCE(string_agg(jsonb_build_array(r.listing_id, r.revision, r.source_fingerprint, r.target_fingerprint)::text, '' ORDER BY r.listing_id, r.revision, r.source_fingerprint, r.target_fingerprint), ''))
                 FROM moysklad_mapping_package_reviews r JOIN moysklad_listing_mapping_revisions m
                 ON m.company_id=r.company_id AND m.connection_id=r.connection_id AND m.listing_id=r.listing_id AND m.revision=r.revision
                 WHERE r.company_id=:company AND r.connection_id=:connection AND m.listing_snapshot->>'marketplace'=:marketplace) AS reviews,
                (SELECT jsonb_build_array(is_active, account_id)::text FROM moysklad_connections WHERE company_id=:company AND id=:connection) AS connection,
                (SELECT COUNT(*) FROM moysklad_sync_cursors WHERE company_id=:company AND connection_id=:connection AND entity_type IN ('product','variant') AND last_completed_at IS NOT NULL) AS cursors
            SQL, ['company' => $companyId, 'connection' => $connectionId, 'marketplace' => $marketplace]);

        return new ExternalMappingSourceVersion(hash('sha256', json_encode([$source->fingerprint, $catalog, $state,
            $this->settings->get($companyId, $connectionId, $marketplace)->fingerprint(), AutomaticMappingGuard::RULE_VERSION, $sizeCharacteristicId], \JSON_THROW_ON_ERROR)), $source->total);
    }

    public function page(string $companyId, string $connectionId, string $marketplace, int $page, ?string $status = null, ?string $reason = null, ?bool $manualReview = null): MappingDiagnosticPage
    {
        Assert::uuid($companyId);
        Assert::uuid($connectionId);
        Assert::inArray($marketplace, ['ozon', 'wildberries']);
        Assert::greaterThanEq($page, 1);
        if (null !== $status) {
            Assert::notNull(AutomaticMappingDecisionStatus::tryFrom($status));
        }
        if (null !== $reason) {
            Assert::notNull(MappingDiagnosticReason::tryFrom($reason));
        }
        $params = ['company' => $companyId, 'connection' => $connectionId, 'marketplace' => $marketplace];
        $where = ' FROM moysklad_mapping_diagnostic_runs WHERE company_id=:company AND connection_id=:connection AND marketplace=:marketplace';
        $latest = $this->db->fetchAssociative('SELECT '.self::RUN_FIELDS.$where.' ORDER BY requested_at DESC, id DESC LIMIT 1', $params);
        $completed = $this->db->fetchAssociative('SELECT '.self::RUN_FIELDS.$where." AND status='completed' ORDER BY finished_at DESC, id DESC LIMIT 1", $params);
        $latestRun = false === $latest ? null : MappingDiagnosticRun::fromRow($latest);
        $completedRun = false === $completed ? null : MappingDiagnosticRun::fromRow($completed);
        if (null === $completedRun) {
            return new MappingDiagnosticPage($latestRun, null, false, [], $page, 0);
        }
        $stale = $completedRun->inputFingerprint !== $this->inputVersion($companyId, $connectionId, $marketplace, $completedRun->sizeCharacteristicId)->fingerprint;
        $params = ['company' => $companyId, 'run' => $completedRun->id];
        $where = ' FROM moysklad_mapping_diagnostic_rows WHERE company_id=:company AND run_id=:run';
        if (null !== $status) {
            $where .= ' AND status=:status';
            $params['status'] = $status;
        }
        if (null !== $reason) {
            $where .= ' AND jsonb_exists(reasons, :reason)';
            $params['reason'] = $reason;
        }
        if (null !== $manualReview) {
            $where .= $manualReview ? ' AND manual_review' : ' AND NOT manual_review';
        }
        $total = (int) $this->db->fetchOne('SELECT COUNT(*)'.$where, $params);
        $rows = $this->db->fetchFirstColumn('SELECT payload'.$where.' ORDER BY listing_id LIMIT 25 OFFSET '.(($page - 1) * 25), $params);
        $items = array_map(static fn (string $payload): MappingDiagnosticRow => MappingDiagnosticRow::fromArray(json_decode($payload, true, 512, \JSON_THROW_ON_ERROR)), $rows);

        return new MappingDiagnosticPage($latestRun, $completedRun, $stale, $items, $page, $total);
    }
}
