<?php

declare(strict_types=1);

namespace App\MoySklad\Application\Action;

use App\MoySklad\Exception\ListingMappingException;
use App\MoySklad\Message\UndoMappingBulkJobMessage;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Webmozart\Assert\Assert;

/** Ставит отмену пакета в очередь. Повтор для уже отменяемого пакета ничего не меняет и снова отправляет сообщение. */
final readonly class StartMappingBulkUndoAction
{
    public function __construct(private Connection $db, private ClockInterface $clock, private MessageBusInterface $bus)
    {
    }

    public function __invoke(string $companyId, string $connectionId, string $marketplace, string $jobId, string $actorUserId): void
    {
        foreach ([$companyId, $connectionId, $jobId, $actorUserId] as $uuid) {
            Assert::uuid($uuid);
        }
        $this->db->transactional(function () use ($companyId, $connectionId, $marketplace, $jobId, $actorUserId): void {
            $this->db->fetchOne("SELECT pg_advisory_xact_lock(hashtext('ms-mapping-bulk'), hashtext(?))", [$companyId.':'.$connectionId.':'.$marketplace]);
            $job = $this->db->fetchAssociative('SELECT status, undo_status, accepted FROM moysklad_mapping_bulk_jobs WHERE company_id=? AND connection_id=? AND marketplace=? AND id=? FOR UPDATE',
                [$companyId, $connectionId, $marketplace, $jobId]);
            if (false === $job) {
                throw new ListingMappingException('not_found', 'Пакет не найден.');
            }
            if (null !== $job['undo_status']) {
                return;
            }
            if (!in_array($job['status'], ['completed', 'failed'], true)) {
                throw new ListingMappingException('bulk_busy', 'Пакет ещё обрабатывается. Отменить можно после окончания.');
            }
            if (0 === (int) $job['accepted']) {
                throw new ListingMappingException('nothing_to_undo', 'Пакет не принял ни одной связи — отменять нечего.');
            }
            $busy = $this->db->fetchOne("SELECT 1 FROM moysklad_mapping_bulk_jobs WHERE company_id=? AND connection_id=? AND marketplace=? AND id<>?
                AND (status IN ('queued','running') OR undo_status IN ('queued','running')) LIMIT 1", [$companyId, $connectionId, $marketplace, $jobId]);
            if (false !== $busy) {
                throw new ListingMappingException('bulk_busy', 'Обрабатывается другой пакет. Дождитесь окончания и повторите.');
            }
            $this->db->executeStatement("UPDATE moysklad_mapping_bulk_jobs SET undo_status='queued', undo_actor_user_id=?, heartbeat_at=? WHERE company_id=? AND id=?",
                [$actorUserId, $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v'), $companyId, $jobId]);
        });
        $this->bus->dispatch(new UndoMappingBulkJobMessage($companyId, $jobId));
    }
}
