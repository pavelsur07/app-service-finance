<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\ClockInterface;
use Webmozart\Assert\Assert;

/**
 * Журнал запусков ИИ (`ai_run`). Запись — одна инструкция SQL (своя транзакция, если вызывающий
 * не открыл свою): журнал должен пережить сбой компании, после которого EntityManager закрыт.
 */
final readonly class AiRunJournal
{
    public const KEEP_DAYS = 90;

    public function __construct(
        private Connection $db,
        private ClockInterface $clock,
    ) {
    }

    public function record(
        string $companyId,
        string $taskType,
        int $requested,
        int $tasks,
        int $redispatched,
        int $expired,
        ?string $skippedReason,
        ?string $error,
    ): void {
        Assert::uuid($companyId);
        $this->db->executeStatement(
            'INSERT INTO ai_run (id, company_id, task_type, ran_at, requested, tasks, redispatched, expired, skipped_reason, error)
             VALUES (:id, :company, :type, :at, :requested, :tasks, :redispatched, :expired, :skipped, :error)',
            [
                'id' => Uuid::uuid7()->toString(),
                'company' => strtolower($companyId),
                'type' => $taskType,
                'at' => $this->now()->format('Y-m-d H:i:s'),
                'requested' => $requested,
                'tasks' => $tasks,
                'redispatched' => $redispatched,
                'expired' => $expired,
                'skipped' => null === $skippedReason ? null : mb_substr($skippedReason, 0, 32),
                'error' => null === $error ? null : mb_substr($error, 0, 255),
            ],
        );
    }

    /**
     * Время последнего запуска по каждой компании; компаний без запусков в ответе нет.
     *
     * @param list<string> $companyIds
     *
     * @return array<string, \DateTimeImmutable>
     */
    public function lastRunAt(array $companyIds): array
    {
        if ([] === $companyIds) {
            return [];
        }
        $rows = $this->db->fetchAllKeyValue(
            'SELECT company_id::text, MAX(ran_at) FROM ai_run WHERE company_id IN (:ids) GROUP BY company_id',
            ['ids' => array_values(array_unique(array_map('strtolower', $companyIds)))],
            ['ids' => ArrayParameterType::STRING],
        );

        return array_map(static fn (mixed $at): \DateTimeImmutable => new \DateTimeImmutable((string) $at), $rows);
    }

    /** Удаляет запуски старше KEEP_DAYS; возвращает число строк. */
    public function purgeOld(): int
    {
        return (int) $this->db->executeStatement(
            'DELETE FROM ai_run WHERE ran_at < :before',
            ['before' => $this->now()->modify(sprintf('-%d days', self::KEEP_DAYS))->format('Y-m-d H:i:s')],
        );
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }
}
