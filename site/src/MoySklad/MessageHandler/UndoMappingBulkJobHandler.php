<?php

declare(strict_types=1);

namespace App\MoySklad\MessageHandler;

use App\MoySklad\Application\Action\ChangeListingMappingAction;
use App\MoySklad\Application\Command\ChangeListingMappingCommand;
use App\MoySklad\Application\MappingDiagnosticsRefresher;
use App\MoySklad\Enum\ListingMappingOperation;
use App\MoySklad\Exception\ListingMappingException;
use App\MoySklad\Infrastructure\Repository\MoySkladListingMappingRepository;
use App\MoySklad\Message\UndoMappingBulkJobMessage;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Отмена пакета. Фазы: claim (undo queued → running) → порции по 100 принятых строк → finalize.
 * Связь снимается (CLEAR) только если текущая ревизия листинга — созданная пакетом; иначе строка пропускается.
 * requestId = UUIDv5(пакет, листинг, undo): повтор delivery не создаёт второй ревизии.
 */
#[AsMessageHandler]
final readonly class UndoMappingBulkJobHandler
{
    public function __construct(
        private Connection $db, private ChangeListingMappingAction $writer, private MoySkladListingMappingRepository $revisions,
        private EntityManagerInterface $em, private ClockInterface $clock, private LoggerInterface $logger,
        private MappingDiagnosticsRefresher $diagnostics,
    ) {
    }

    public function __invoke(UndoMappingBulkJobMessage $message): void
    {
        $job = $this->job($message);
        if (null === $job || !in_array($job['undo_status'], ['queued', 'running'], true)) {
            return;
        }
        if (!$this->db->fetchOne("SELECT pg_try_advisory_lock(hashtext('ms-mapping-bulk-job'), hashtext(?))", [$message->jobId])) {
            return;
        }
        try {
            $this->db->executeStatement("UPDATE moysklad_mapping_bulk_jobs SET undo_status='running', heartbeat_at=? WHERE company_id=? AND id=? AND undo_status IN ('queued','running')", [$this->now(), $message->companyId, $message->jobId]);
            while ($this->batch($job)) {
                $this->em->clear();
            }
            $this->db->executeStatement("UPDATE moysklad_mapping_bulk_jobs SET undo_status='completed', undo_finished_at=?, heartbeat_at=? WHERE company_id=? AND id=? AND undo_status='running'",
                [$this->now(), $this->now(), $message->companyId, $message->jobId]);
        } catch (\Throwable $error) {
            $this->db->executeStatement("UPDATE moysklad_mapping_bulk_jobs SET undo_status='failed', undo_finished_at=?, heartbeat_at=? WHERE company_id=? AND id=? AND undo_status='running'",
                [$this->now(), $this->now(), $message->companyId, $message->jobId]);
            $this->logger->error('MoySklad bulk mapping undo failed.', ['reason' => 'bulk_undo_failed', 'companyId' => $message->companyId, 'jobId' => $message->jobId, 'exceptionClass' => $error::class]);
        } finally {
            $this->db->fetchOne("SELECT pg_advisory_unlock(hashtext('ms-mapping-bulk-job'), hashtext(?))", [$message->jobId]);
        }
        // Отчёт после пакета устарел: пересчёт сразу, после commit. Потеря dispatch не страшна — его подберёт cron-проход.
        try {
            $this->diagnostics->refreshIfStale($message->companyId, $job['connection_id'], $job['marketplace']);
        } catch (\Throwable $error) {
            $this->logger->warning('MoySklad diagnostics refresh after bulk job failed.', ['companyId' => $message->companyId, 'jobId' => $message->jobId, 'exceptionClass' => $error::class]);
        }
    }

    /** @param array<string,mixed> $job */
    private function batch(array $job): bool
    {
        $items = $this->db->fetchAllAssociative("SELECT listing_id, revision FROM moysklad_mapping_bulk_job_items
            WHERE company_id=? AND job_id=? AND status='accepted' AND undo_status IS NULL ORDER BY listing_id LIMIT 100", [$job['company_id'], $job['id']]);
        foreach ($items as $item) {
            $listingId = $item['listing_id'];
            $requestId = ApplyMappingBulkJobHandler::requestId($job['id'], $listingId, 'undo');
            if (null !== $this->revisions->acceptedRequest($job['company_id'], $requestId)) {
                $this->finish($job, $listingId, true);
                continue;
            }
            $current = $this->revisions->latest($job['company_id'], $job['connection_id'], $listingId);
            if (null === $current || $current->getRevision() !== (int) $item['revision'] || ListingMappingOperation::SET !== $current->getOperation()) {
                $this->finish($job, $listingId, false);
                continue;
            }
            try {
                ($this->writer)(new ChangeListingMappingCommand($job['company_id'], $job['undo_actor_user_id'], $job['connection_id'], $listingId,
                    ListingMappingOperation::CLEAR, null, null, (int) $item['revision'], $requestId));
                $this->finish($job, $listingId, true);
            } catch (ListingMappingException $error) {
                $this->finish($job, $listingId, false);
                if (!$this->em->isOpen()) {
                    throw $error;
                }
            }
        }

        return [] !== $items;
    }

    /** @param array<string,mixed> $job */
    private function finish(array $job, string $listingId, bool $undone): void
    {
        $counter = $undone ? 'undone' : 'undo_skipped';
        $this->db->transactional(function () use ($job, $listingId, $undone, $counter): void {
            $changed = $this->db->executeStatement('UPDATE moysklad_mapping_bulk_job_items SET undo_status=?, message=COALESCE(?, message)
                WHERE company_id=? AND job_id=? AND listing_id=? AND undo_status IS NULL',
                [$undone ? 'undone' : 'skipped', $undone ? null : 'Не отменено: связь меняли после пакета.', $job['company_id'], $job['id'], $listingId]);
            if (1 === $changed) {
                $this->db->executeStatement("UPDATE moysklad_mapping_bulk_jobs SET {$counter}={$counter}+1, heartbeat_at=? WHERE company_id=? AND id=?", [$this->now(), $job['company_id'], $job['id']]);
            }
        });
    }

    /** @return array<string,mixed>|null */
    private function job(UndoMappingBulkJobMessage $message): ?array
    {
        $job = $this->db->fetchAssociative('SELECT id, company_id, connection_id, marketplace, undo_status, undo_actor_user_id FROM moysklad_mapping_bulk_jobs WHERE company_id=? AND id=?', [$message->companyId, $message->jobId]);

        return false === $job ? null : $job;
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
