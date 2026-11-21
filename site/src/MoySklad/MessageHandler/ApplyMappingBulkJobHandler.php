<?php

declare(strict_types=1);

namespace App\MoySklad\MessageHandler;

use App\Marketplace\DTO\ListingMetaDTO;
use App\Marketplace\DTO\ListingMetaPageDTO;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MoySklad\Application\Action\ChangeListingMappingAction;
use App\MoySklad\Application\Command\ChangeListingMappingCommand;
use App\MoySklad\Application\DTO\AutomaticMappingDecision;
use App\MoySklad\Application\DTO\AutomaticMappingGuard;
use App\MoySklad\Application\DTO\AutomaticMappingPreviewPage;
use App\MoySklad\Application\MappingDiagnosticsRefresher;
use App\MoySklad\Domain\AutomaticListingMappingPolicy;
use App\MoySklad\Enum\AutomaticMappingDecisionStatus;
use App\MoySklad\Enum\ListingMappingOperation;
use App\MoySklad\Exception\ListingMappingException;
use App\MoySklad\Infrastructure\Query\AutomaticMappingPreviewQuery;
use App\MoySklad\Infrastructure\Repository\MoySkladListingMappingRepository;
use App\MoySklad\Message\ApplyMappingBulkJobMessage;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Пакет принятия предложений. Фазы: claim (queued → running) → порции по 100 → finalize (completed).
 * Каждая строка — свежая проверка evaluator'ом и запись существующим writer'ом с guard (previewId = id пакета);
 * requestId = UUIDv5(пакет, листинг), поэтому повтор delivery распознаёт уже принятую строку и не пишет дубль.
 * Итог строки и счётчики пакета коммитятся вместе. Зависший пакет переотправляет app:moysklad:mapping-bulk:requeue.
 */
#[AsMessageHandler]
final readonly class ApplyMappingBulkJobHandler
{
    private const BATCH = 100;

    public function __construct(
        private Connection $db, private MarketplaceFacade $marketplace, private AutomaticMappingPreviewQuery $evaluator,
        private AutomaticListingMappingPolicy $policy, private ChangeListingMappingAction $writer, private MoySkladListingMappingRepository $revisions,
        private EntityManagerInterface $em, private ClockInterface $clock, private LoggerInterface $logger,
        private MappingDiagnosticsRefresher $diagnostics,
    ) {
    }

    public static function requestId(string $jobId, string $listingId, string $operation = 'apply'): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'moysklad-mapping-bulk:'.$operation.':'.strtolower($jobId).':'.strtolower($listingId))->toString();
    }

    public function __invoke(ApplyMappingBulkJobMessage $message): void
    {
        $job = $this->job($message);
        if (null === $job || !in_array($job['status'], ['queued', 'running'], true)) {
            return;
        }
        // Второй worker с тем же пакетом (requeue + исходная доставка) просто выходит.
        if (!$this->db->fetchOne("SELECT pg_try_advisory_lock(hashtext('ms-mapping-bulk-job'), hashtext(?))", [$message->jobId])) {
            return;
        }
        $scope = $job;
        try {
            $this->db->executeStatement("UPDATE moysklad_mapping_bulk_jobs SET status='running', heartbeat_at=? WHERE company_id=? AND id=? AND status IN ('queued','running')", [$this->now(), $message->companyId, $message->jobId]);
            while (null !== ($job = $this->job($message)) && 'running' === $job['status'] && $this->batch($job)) {
                $this->em->clear();
            }
        } catch (\Throwable $error) {
            $this->db->executeStatement("UPDATE moysklad_mapping_bulk_jobs SET status='failed', error_reason='bulk_failed', finished_at=?, heartbeat_at=? WHERE company_id=? AND id=? AND status='running'",
                [$this->now(), $this->now(), $message->companyId, $message->jobId]);
            $this->logger->error('MoySklad bulk mapping job failed.', ['reason' => 'bulk_failed', 'companyId' => $message->companyId, 'jobId' => $message->jobId, 'exceptionClass' => $error::class]);
        } finally {
            $this->db->fetchOne("SELECT pg_advisory_unlock(hashtext('ms-mapping-bulk-job'), hashtext(?))", [$message->jobId]);
        }
        // Отчёт после пакета устарел: пересчёт сразу, после commit. Потеря dispatch не страшна — его подберёт cron-проход.
        try {
            $this->diagnostics->refreshIfStale($message->companyId, $scope['connection_id'], $scope['marketplace']);
        } catch (\Throwable $error) {
            $this->logger->warning('MoySklad diagnostics refresh after bulk job failed.', ['companyId' => $message->companyId, 'jobId' => $message->jobId, 'exceptionClass' => $error::class]);
        }
    }

    /** @param array<string,mixed> $job */
    private function batch(array $job): bool
    {
        if ('cabinet' === $job['scope']) {
            $sources = $this->marketplace->getListingsAfterForExternalMapping($job['company_id'], $job['marketplace'], $job['after_listing_id'], self::BATCH);
            if ([] === $sources) {
                return $this->finalize($job);
            }
            $page = $this->evaluate($job, $sources);
            foreach ($sources as $source) {
                $decision = $page->decisions[$source->id];
                if (AutomaticMappingDecisionStatus::READY !== $decision->status || null === $decision->target) {
                    continue;
                }
                $this->db->executeStatement("INSERT INTO moysklad_mapping_bulk_job_items (company_id, job_id, listing_id, target_type, target_external_id, status)
                    VALUES (?, ?, ?, ?, ?, 'pending') ON CONFLICT DO NOTHING", [$job['company_id'], $job['id'], $source->id, $decision->target->type, $decision->target->externalId]);
                $this->process($job, $source->id, $decision->target->type, $decision->target->externalId, $source, $page);
            }
            $this->db->executeStatement('UPDATE moysklad_mapping_bulk_jobs SET after_listing_id=?, heartbeat_at=? WHERE company_id=? AND id=?',
                [$sources[array_key_last($sources)]->id, $this->now(), $job['company_id'], $job['id']]);

            return self::BATCH === count($sources) || $this->finalize($job);
        }
        $items = $this->db->fetchAllAssociative("SELECT listing_id, target_type, target_external_id FROM moysklad_mapping_bulk_job_items
            WHERE company_id=? AND job_id=? AND status='pending' ORDER BY listing_id LIMIT ".self::BATCH, [$job['company_id'], $job['id']]);
        if ([] === $items) {
            return $this->finalize($job);
        }
        $sources = $this->marketplace->getListingsMetaByIds($job['company_id'], array_column($items, 'listing_id'));
        $page = $this->evaluate($job, array_values($sources));
        foreach ($items as $item) {
            $source = $sources[strtolower($item['listing_id'])] ?? null;
            if (null === $source) {
                $this->finish($job, $item['listing_id'], 'skipped', 'Листинг больше недоступен.');
                continue;
            }
            $this->process($job, $item['listing_id'], (string) $item['target_type'], (string) $item['target_external_id'], $source, $page);
        }

        return true;
    }

    /** @param array<string,mixed> $job */
    private function process(array $job, string $listingId, string $targetType, string $targetExternalId, ListingMetaDTO $source, AutomaticMappingPreviewPage $page): void
    {
        $requestId = self::requestId($job['id'], $listingId);
        $accepted = $this->revisions->acceptedRequest($job['company_id'], $requestId);
        if (null !== $accepted) {
            $this->finish($job, $listingId, 'accepted', null, $accepted->getRevision());

            return;
        }
        $decision = $page->decisions[$source->id];
        if (!$this->sameProposal($decision, $targetType, $targetExternalId)) {
            $this->finish($job, $listingId, 'skipped', 'Соответствие изменилось после проверки: '.mb_lcfirst($decision->status->label()).'.');

            return;
        }
        $barcodes = $page->barcodes[$source->id] ?? [];
        $guard = new AutomaticMappingGuard($job['id'], AutomaticMappingGuard::RULE_VERSION, $this->policy->sourceFingerprint($source, $barcodes, $page->settings), $job['size_characteristic_id'], $decision->fingerprint());
        try {
            $state = ($this->writer)(new ChangeListingMappingCommand($job['company_id'], $job['actor_user_id'], $job['connection_id'], $listingId,
                ListingMappingOperation::SET, $targetType, $targetExternalId, 0, $requestId, $guard));
            $this->finish($job, $listingId, 'accepted', null, $state->revision);
        } catch (ListingMappingException $error) {
            if (in_array($error->reason, ['automatic_mapping_conflict', 'stale_mapping'], true)) {
                $this->finish($job, $listingId, 'skipped', 'Соответствие изменилось после проверки.');
            } else {
                $this->finish($job, $listingId, 'conflict', mb_substr($error->getMessage(), 0, 255));
            }
            if (!$this->em->isOpen()) {
                throw $error;
            }
        } catch (\Throwable $error) {
            $this->finish($job, $listingId, 'failed', 'Техническая ошибка. Проверьте историю связи.');
            throw $error;
        }
    }

    private function sameProposal(AutomaticMappingDecision $decision, string $targetType, string $targetExternalId): bool
    {
        return AutomaticMappingDecisionStatus::READY === $decision->status && null !== $decision->target
            && $decision->target->type === $targetType && strtolower($decision->target->externalId) === strtolower($targetExternalId);
    }

    /** @param array<string,mixed> $job */
    private function finish(array $job, string $listingId, string $status, ?string $message, ?int $revision = null): void
    {
        $counter = ['accepted' => 'accepted', 'skipped' => 'skipped', 'conflict' => 'conflicts', 'failed' => 'failed'][$status];
        $this->db->transactional(function () use ($job, $listingId, $status, $message, $revision, $counter): void {
            $changed = $this->db->executeStatement("UPDATE moysklad_mapping_bulk_job_items SET status=?, message=?, revision=?, processed_at=?
                WHERE company_id=? AND job_id=? AND listing_id=? AND status='pending'", [$status, $message, $revision, $this->now(), $job['company_id'], $job['id'], $listingId]);
            if (1 === $changed) {
                $this->db->executeStatement("UPDATE moysklad_mapping_bulk_jobs SET processed=processed+1, {$counter}={$counter}+1, heartbeat_at=? WHERE company_id=? AND id=?",
                    [$this->now(), $job['company_id'], $job['id']]);
            }
        });
    }

    /** @param array<string,mixed> $job */
    private function finalize(array $job): bool
    {
        $this->db->executeStatement("UPDATE moysklad_mapping_bulk_jobs SET status='completed', finished_at=?, heartbeat_at=? WHERE company_id=? AND id=? AND status='running'",
            [$this->now(), $this->now(), $job['company_id'], $job['id']]);

        return false;
    }

    /**
     * @param array<string,mixed> $job
     * @param list<ListingMetaDTO> $sources
     */
    private function evaluate(array $job, array $sources): AutomaticMappingPreviewPage
    {
        return $this->evaluator->evaluate($job['company_id'], $job['connection_id'], $job['marketplace'], new ListingMetaPageDTO($sources, 1, self::BATCH, count($sources)), '', $job['size_characteristic_id']);
    }

    /** @return array<string,mixed>|null */
    private function job(ApplyMappingBulkJobMessage $message): ?array
    {
        $job = $this->db->fetchAssociative('SELECT id, company_id, connection_id, marketplace, actor_user_id, scope, size_characteristic_id, status, after_listing_id
            FROM moysklad_mapping_bulk_jobs WHERE company_id=? AND id=?', [$message->companyId, $message->jobId]);

        return false === $job ? null : $job;
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
