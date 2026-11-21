<?php

declare(strict_types=1);

namespace App\MoySklad\Application\Action;

use App\MoySklad\Application\Command\StartMappingBulkJobCommand;
use App\MoySklad\Exception\ListingMappingException;
use App\MoySklad\Infrastructure\Query\MappingDiagnosticsQuery;
use App\MoySklad\Message\ApplyMappingBulkJobMessage;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Создаёт пакет принятия предложений. Ключ повтора — jobId формы: повтор возвращает тот же пакет.
 * Один активный пакет (или отмена) на подключение и маркетплейс — под advisory-lock scope.
 * Строки отчёта снимаются в пакет той же транзакцией: новая диагностика посреди пакета его не обрывает.
 */
final readonly class StartMappingBulkJobAction
{
    public function __construct(private Connection $db, private MappingDiagnosticsQuery $reports, private ClockInterface $clock, private MessageBusInterface $bus)
    {
    }

    /** @return bool true — пакет создан, false — повтор существующего */
    public function __invoke(StartMappingBulkJobCommand $c): bool
    {
        $created = $this->db->transactional(function () use ($c): bool {
            if (false === $this->db->fetchOne('SELECT id FROM moysklad_connections WHERE company_id=? AND id=? FOR SHARE', [$c->companyId, $c->connectionId])) {
                throw new ListingMappingException('not_found', 'Подключение не найдено.');
            }
            $this->db->fetchOne("SELECT pg_advisory_xact_lock(hashtext('ms-mapping-bulk'), hashtext(?))", [$c->companyId.':'.$c->connectionId.':'.$c->marketplace]);
            $existing = $this->db->fetchAssociative('SELECT company_id, connection_id, marketplace, scope FROM moysklad_mapping_bulk_jobs WHERE id=?', [$c->jobId]);
            if (false !== $existing) {
                if ([$c->companyId, $c->connectionId, $c->marketplace, $c->scope] !== [$existing['company_id'], $existing['connection_id'], $existing['marketplace'], $existing['scope']]) {
                    throw new ListingMappingException('idempotency_conflict', 'Ключ запроса уже использован для другого действия.');
                }

                return false;
            }
            $busy = $this->db->fetchOne("SELECT 1 FROM moysklad_mapping_bulk_jobs WHERE company_id=? AND connection_id=? AND marketplace=?
                AND (status IN ('queued','running') OR undo_status IN ('queued','running')) LIMIT 1", [$c->companyId, $c->connectionId, $c->marketplace]);
            if (false !== $busy) {
                throw new ListingMappingException('bulk_busy', 'Пакет уже обрабатывается. Дождитесь окончания и повторите.');
            }
            $runId = null;
            $size = $c->sizeCharacteristicId;
            if ('cabinet' !== $c->scope) {
                $report = $this->reports->page($c->companyId, $c->connectionId, $c->marketplace, 1, 'ready');
                if (null === $report->completedRun) {
                    throw new ListingMappingException('report_missing', 'Сначала запустите диагностику.');
                }
                if ($report->stale) {
                    throw new ListingMappingException('report_stale', 'Отчёт устарел: каталог или связи изменились. Обновите диагностику и повторите.');
                }
                $runId = $report->completedRun->id;
                $size = $report->completedRun->sizeCharacteristicId;
            }
            $now = $this->now();
            $this->db->executeStatement("INSERT INTO moysklad_mapping_bulk_jobs (id, company_id, connection_id, marketplace, actor_user_id, origin, scope, run_id, size_characteristic_id, status, created_at, heartbeat_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'queued', ?, ?)", [$c->jobId, $c->companyId, $c->connectionId, $c->marketplace, $c->actorUserId, $c->origin, $c->scope, $runId, $size, $now, $now]);
            if (null !== $runId) {
                $filter = 'selected' === $c->scope ? ' AND listing_id IN (:ids)' : '';
                $total = (int) $this->db->executeStatement(<<<SQL
                    INSERT INTO moysklad_mapping_bulk_job_items (company_id, job_id, listing_id, target_type, target_external_id, status)
                    SELECT company_id, :job, listing_id, payload->'decision'->'target'->>'type', (payload->'decision'->'target'->>'externalId')::uuid, 'pending'
                    FROM moysklad_mapping_diagnostic_rows WHERE company_id=:company AND run_id=:run AND status='ready'{$filter}
                    SQL, ['job' => $c->jobId, 'company' => $c->companyId, 'run' => $runId, 'ids' => $c->listingIds], ['ids' => ArrayParameterType::STRING]);
                if ('selected' === $c->scope && $total !== count($c->listingIds)) {
                    throw new ListingMappingException('invalid_selection', 'Выберите строки-предложения из текущего отчёта.');
                }
                if (0 === $total) {
                    throw new ListingMappingException('nothing_to_apply', 'В отчёте нет предложений для принятия.');
                }
                $this->db->executeStatement('UPDATE moysklad_mapping_bulk_jobs SET total=? WHERE company_id=? AND id=?', [$total, $c->companyId, $c->jobId]);
            }

            return true;
        });
        // Потерю сообщения после commit закрывает app:moysklad:mapping-bulk:requeue; повторная доставка безопасна.
        $this->bus->dispatch(new ApplyMappingBulkJobMessage($c->companyId, $c->jobId));

        return $created;
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
