<?php

declare(strict_types=1);

namespace App\MoySklad\MessageHandler;

use App\Marketplace\DTO\ListingMetaPageDTO;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MoySklad\Application\DTO\AutomaticMappingGuard;
use App\MoySklad\Application\DTO\MappingDiagnosticRow;
use App\MoySklad\Enum\AutomaticMappingDecisionStatus;
use App\MoySklad\Enum\MappingDiagnosticReason;
use App\MoySklad\Exception\ListingMappingException;
use App\MoySklad\Infrastructure\Query\AutomaticMappingPreviewQuery;
use App\MoySklad\Infrastructure\Query\MappingDiagnosticsQuery;
use App\MoySklad\Infrastructure\Query\MappingSettingsQuery;
use App\MoySklad\Message\DiagnoseMappingsMessage;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

/** One delivery owns the scan; each <=100-row batch commits its cursor and results together. */
#[AsMessageHandler]
final readonly class DiagnoseMappingsHandler
{
    public function __construct(private Connection $db, private MarketplaceFacade $marketplace, private AutomaticMappingPreviewQuery $evaluator, private MappingDiagnosticsQuery $reports, private MappingSettingsQuery $settings, private ClockInterface $clock, private LoggerInterface $logger)
    {
    }

    public function __invoke(DiagnoseMappingsMessage $message): void
    {
        if (false === $this->db->fetchOne('SELECT id FROM moysklad_connections WHERE company_id=? AND id=?', [$message->companyId, $message->connectionId])) {
            return;
        }
        $key = $message->companyId.':'.$message->connectionId.':'.$message->marketplace;
        if (!$this->db->fetchOne("SELECT pg_try_advisory_lock(hashtext('ms-mapping-diagnostics'), hashtext(?))", [$key])) {
            throw new RecoverableMessageHandlingException('Mapping diagnostics is already running.', retryDelay: 5000);
        }
        try {
            $this->initialize($message);
            while ($this->batch($message)) {
                // No next-message dispatch window: retry of this delivery resumes the committed cursor.
            }
        } catch (\Throwable $error) {
            try {
                $this->db->executeStatement("UPDATE moysklad_mapping_diagnostic_runs SET status='failed', error_reason='diagnostic_failed', finished_at=? WHERE company_id=? AND connection_id=? AND id=? AND status='running'", [$this->now(), $message->companyId, $message->connectionId, $message->runId]);
            } catch (\Throwable) {
                $this->logger->error('Could not record mapping diagnostic failure.', ['companyId' => $message->companyId, 'runId' => $message->runId]);
            }
            $this->logger->error('Mapping diagnostics failed.', ['companyId' => $message->companyId, 'connectionId' => $message->connectionId, 'runId' => $message->runId, 'exceptionClass' => $error::class]);
            throw $error;
        } finally {
            try {
                $this->db->fetchOne("SELECT pg_advisory_unlock(hashtext('ms-mapping-diagnostics'), hashtext(?))", [$key]);
            } catch (\Throwable) {
                // A lost DB session already released its session lock.
            }
        }
    }

    private function initialize(DiagnoseMappingsMessage $message): void
    {
        $this->db->transactional(function () use ($message): void {
            $existing = $this->run($message);
            if (false !== $existing) {
                if ($existing['marketplace'] !== $message->marketplace || $existing['size_characteristic_id'] !== $message->sizeCharacteristicId) {
                    throw new ListingMappingException('idempotency_conflict', 'Запрос диагностики уже использован.');
                }
                if ('failed' === $existing['status']) {
                    $this->db->executeStatement("UPDATE moysklad_mapping_diagnostic_runs SET status='running', error_reason=NULL, finished_at=NULL WHERE company_id=? AND id=?", [$message->companyId, $message->runId]);
                }

                return;
            }
            $version = $this->reports->inputVersion($message->companyId, $message->connectionId, $message->marketplace, $message->sizeCharacteristicId);
            $this->db->executeStatement('INSERT INTO moysklad_mapping_diagnostic_runs
                (id, company_id, connection_id, marketplace, size_characteristic_id, rule_version, settings_fingerprint, input_fingerprint, status, requested_at, total, counts)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$message->runId, $message->companyId, $message->connectionId, $message->marketplace, $message->sizeCharacteristicId, AutomaticMappingGuard::RULE_VERSION,
                    $this->settings->get($message->companyId, $message->connectionId, $message->marketplace)->fingerprint(), $version->fingerprint, 'running', $this->now(), $version->total, json_encode($this->emptyCounts(), \JSON_THROW_ON_ERROR)]);
        });
    }

    /** @return array<string,mixed>|false */
    private function run(DiagnoseMappingsMessage $message): array|false
    {
        return $this->db->fetchAssociative('SELECT '.MappingDiagnosticsQuery::RUN_FIELDS.' FROM moysklad_mapping_diagnostic_runs WHERE company_id=? AND connection_id=? AND id=? FOR UPDATE', [$message->companyId, $message->connectionId, $message->runId]);
    }

    private function batch(DiagnoseMappingsMessage $message): bool
    {
        return $this->db->transactional(function () use ($message): bool {
            $run = $this->run($message);
            if (false === $run || 'running' !== $run['status']) {
                return false;
            }
            $version = $this->reports->inputVersion($message->companyId, $message->connectionId, $message->marketplace, $message->sizeCharacteristicId);
            if ($run['input_fingerprint'] !== $version->fingerprint || AutomaticMappingGuard::RULE_VERSION !== $run['rule_version']) {
                $this->markStale($message);

                return false;
            }
            $sources = $this->marketplace->getListingsAfterForExternalMapping($message->companyId, $message->marketplace, $run['after_listing_id'], 100);
            $page = $this->evaluator->evaluate($message->companyId, $message->connectionId, $message->marketplace,
                new ListingMetaPageDTO($sources, 1, 100, $version->total), '', $message->sizeCharacteristicId);
            /** @var array<string,int> $counts */
            $counts = json_decode((string) $run['counts'], true, 512, \JSON_THROW_ON_ERROR);
            foreach ($sources as $source) {
                $row = new MappingDiagnosticRow($source, $page->decisions[$source->id], $page->barcodes[$source->id] ?? []);
                $this->db->executeStatement('INSERT INTO moysklad_mapping_diagnostic_rows (company_id, run_id, listing_id, status, reasons, manual_review, payload, checked_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT DO NOTHING',
                    [$message->companyId, $message->runId, $source->id, $row->decision->status->value, json_encode($row->reasons, \JSON_THROW_ON_ERROR),
                        $row->manualReview ? 'true' : 'false', json_encode($row, \JSON_THROW_ON_ERROR), $this->now()]);
                ++$counts[$row->decision->status->value];
                $counts['manual_review'] += (int) $row->manualReview;
                foreach ($row->reasons as $reason) {
                    ++$counts['reason_'.$reason];
                }
            }
            $after = [] === $sources ? $run['after_listing_id'] : $sources[array_key_last($sources)]->id;
            $done = count($sources) < 100;
            $unchanged = $run['input_fingerprint'] === $this->reports->inputVersion($message->companyId, $message->connectionId, $message->marketplace, $message->sizeCharacteristicId)->fingerprint;
            $status = !$unchanged ? 'stale' : ($done ? 'completed' : 'running');
            $this->db->executeStatement('UPDATE moysklad_mapping_diagnostic_runs SET after_listing_id=?, processed=processed+?, counts=?, status=?, finished_at=? WHERE company_id=? AND connection_id=? AND id=?',
                [$after, count($sources), json_encode($counts, \JSON_THROW_ON_ERROR), $status, 'running' === $status ? null : $this->now(), $message->companyId, $message->connectionId, $message->runId]);

            return 'running' === $status;
        });
    }

    private function markStale(DiagnoseMappingsMessage $message): void
    {
        $this->db->executeStatement("UPDATE moysklad_mapping_diagnostic_runs SET status='stale', finished_at=? WHERE company_id=? AND connection_id=? AND id=?", [$this->now(), $message->companyId, $message->connectionId, $message->runId]);
    }

    /** @return array<string,int> */
    private function emptyCounts(): array
    {
        $counts = array_fill_keys(array_column(AutomaticMappingDecisionStatus::cases(), 'value'), 0);
        foreach (MappingDiagnosticReason::cases() as $reason) {
            $counts['reason_'.$reason->value] = 0;
        }
        $counts['manual_review'] = 0;

        return $counts;
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
