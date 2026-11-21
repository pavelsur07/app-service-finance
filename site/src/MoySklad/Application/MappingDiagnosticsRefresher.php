<?php

declare(strict_types=1);

namespace App\MoySklad\Application;

use App\MoySklad\Infrastructure\Query\MappingDiagnosticsQuery;
use App\MoySklad\Message\DiagnoseMappingsMessage;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Ставит пересчёт отчёта диагностики, когда его нет или данные изменились. Решение только читает БД, поэтому
 * вызывается откуда угодно (cron, страница, конец пакета). runId детерминирован: повтор для тех же данных
 * попадает в уже созданный расчёт, handler его не дублирует.
 */
final readonly class MappingDiagnosticsRefresher
{
    private const STUCK = '-1 hour';

    public function __construct(private Connection $db, private MappingDiagnosticsQuery $reports, private MessageBusInterface $bus, private ClockInterface $clock)
    {
    }

    public function refreshIfStale(string $companyId, string $connectionId, string $marketplace): bool
    {
        if (true !== $this->db->fetchOne('SELECT is_active FROM moysklad_connections WHERE company_id=? AND id=?', [$companyId, $connectionId])) {
            return false;
        }
        $scope = ' FROM moysklad_mapping_diagnostic_runs WHERE company_id=? AND connection_id=? AND marketplace=?';
        $params = [$companyId, $connectionId, $marketplace];
        $latest = $this->db->fetchAssociative('SELECT id, status, size_characteristic_id, requested_at, finished_at'.$scope.' ORDER BY requested_at DESC, id DESC LIMIT 1', $params);
        $stuckBefore = $this->clock->now()->modify(self::STUCK)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
        if (false !== $latest && 'running' === $latest['status']) {
            // Упавший worker: тот же runId, handler продолжит с сохранённого курсора.
            return $latest['requested_at'] < $stuckBefore && $this->dispatch($companyId, $connectionId, $marketplace, $latest['id'], $latest['size_characteristic_id']);
        }
        $completed = $this->db->fetchAssociative('SELECT id, size_characteristic_id, input_fingerprint'.$scope." AND status='completed' ORDER BY finished_at DESC, id DESC LIMIT 1", $params);
        $size = false === $completed ? null : $completed['size_characteristic_id'];
        $version = $this->reports->inputVersion($companyId, $connectionId, $marketplace, $size);
        if (false === $completed ? 0 === $version->total : $completed['input_fingerprint'] === $version->fingerprint) {
            return false;
        }
        $runId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'moysklad-mapping-diagnostics:'.implode('|', [...$params, $size ?? '', $version->fingerprint, false === $completed ? '' : $completed['id']]))->toString();
        // Этот расчёт уже пробовали: упавший повторяем не чаще раза в час, иначе ждём изменения данных.
        if (false !== $latest && $latest['id'] === $runId && !('failed' === $latest['status'] && $latest['finished_at'] < $stuckBefore)) {
            return false;
        }

        return $this->dispatch($companyId, $connectionId, $marketplace, $runId, $size);
    }

    private function dispatch(string $companyId, string $connectionId, string $marketplace, string $runId, ?string $size): bool
    {
        $this->bus->dispatch(new DiagnoseMappingsMessage($companyId, $connectionId, $marketplace, $runId, $size));

        return true;
    }
}
