<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\Action;

use App\ServiceGateway\Application\DTO\AiEvalRun;
use App\ServiceGateway\Application\Service\AiServiceAccess;
use App\ServiceGateway\Exception\AiTaskRejectedException;
use App\ServiceGateway\Exception\ContractViolationException;
use App\ServiceGateway\Exception\ServiceUnavailableException;
use App\ServiceGateway\Infrastructure\Client\AiServiceClient;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Global technical monitor, independent of company grants. No business data is queried.
 * SQL writes are durable before POST; HTTP is outside every application transaction.
 */
final readonly class AiQualityAction
{
    private const SUITE = 'payment_intake';
    private const REQUEST_AT = '03:25';
    private const ACCEPTANCE_AT = '04:00';
    private const PULL_FRESH_SECONDS = 1200;

    public function __construct(
        private Connection $db,
        private AiServiceClient $client,
        private AiServiceAccess $access,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        #[Autowire('%env(bool:AI_SERVICE_EVAL_ENABLED)%')]
        private bool $enabled,
    ) {
    }

    public function request(): string
    {
        if (!$this->isEnabled()) {
            return 'disabled';
        }
        $this->activate();
        $now = $this->now();
        if ($now->format('H:i') < self::REQUEST_AT) {
            return 'waiting';
        }
        $date = $now->format('Y-m-d');
        $this->db->executeStatement(
            "INSERT INTO ai_quality_run (id, suite, run_date, trace_id, status, quality_passed)
             VALUES (:id, :suite, :date, :trace, 'request_pending', false)
             ON CONFLICT (suite, run_date) DO NOTHING",
            ['id' => Uuid::uuid7()->toString(), 'suite' => self::SUITE, 'date' => $date, 'trace' => Uuid::uuid7()->toString()],
        );
        $row = $this->run($date);
        if (null !== $row['remote_id']) {
            return 'already_requested';
        }
        try {
            $accepted = $this->client->submitEvalRun((string) $row['id'], $date, (string) $row['trace_id'], self::SUITE.':'.$date);
        } catch (ServiceUnavailableException) {
            return $this->failure('unavailable');
        } catch (ContractViolationException) {
            return $this->failure('contract_violation');
        } catch (AiTaskRejectedException) {
            return $this->failure('request_rejected');
        }
        // A concurrent pull may already have observed a newer state. POST must not downgrade it.
        $saved = $this->db->executeStatement(
            'UPDATE ai_quality_run SET remote_id = :remote,
             status = CASE WHEN remote_id IS NULL THEN :status ELSE status END,
             configuration_fingerprint = COALESCE(configuration_fingerprint, :fingerprint)
             WHERE id = :id AND (remote_id IS NULL OR remote_id = :remote)',
            ['id' => $row['id'], 'remote' => $accepted->runId, 'status' => $accepted->status, 'fingerprint' => $accepted->configurationFingerprint],
        );
        if (1 !== $saved) {
            return $this->failure('contract_violation');
        }

        return 'accepted';
    }

    public function pull(): string
    {
        if (!$this->isEnabled()) {
            return 'disabled';
        }
        $this->activate();
        $revision = (int) $this->db->fetchOne(
            'UPDATE ai_quality_monitor SET pull_revision = pull_revision + 1 WHERE suite = :suite RETURNING pull_revision',
            ['suite' => self::SUITE],
        );
        try {
            $latest = $this->client->fetchLatestEvalRun();
        } catch (ServiceUnavailableException) {
            return $this->failure('unavailable', $revision);
        } catch (ContractViolationException|AiTaskRejectedException) {
            return $this->failure('contract_violation', $revision);
        }
        try {
            $this->db->transactional(function () use ($latest, $revision): void {
                $current = $this->db->executeStatement(
                    'UPDATE ai_quality_monitor SET last_pull_at = :at, configuration_fingerprint = :fingerprint,
                     remote_enabled = :enabled, error_code = NULL WHERE suite = :suite AND pull_revision = :revision',
                    ['at' => $this->stamp($this->now()), 'fingerprint' => $latest->configurationFingerprint,
                        'enabled' => $latest->enabled, 'suite' => self::SUITE, 'revision' => $revision],
                    ['enabled' => ParameterType::BOOLEAN],
                );
                if (1 === $current && null !== $latest->run) {
                    $this->saveRun($latest->run);
                }
            });
        } catch (ContractViolationException) {
            return $this->failure('contract_violation', $revision);
        }

        return $latest->enabled ? 'updated' : 'remote_disabled';
    }

    public function health(): string
    {
        if (!$this->isEnabled()) {
            return 'disabled';
        }
        $this->activate();
        $monitor = $this->monitor();
        $now = $this->now();
        $date = ($now->format('H:i') < self::ACCEPTANCE_AT ? $now->modify('-1 day') : $now)->format('Y-m-d');
        $row = $this->run($date);
        $reason = null;
        if (null !== $monitor['error_code']) {
            $reason = (string) $monitor['error_code'];
        } elseif (false === $monitor['remote_enabled']) {
            $reason = 'remote_disabled';
        } elseif ([] === $row && $this->time((string) $monitor['enabled_at']) > new \DateTimeImmutable($date.' '.self::REQUEST_AT, new \DateTimeZone('Europe/Moscow'))) {
            return 'waiting';
        } elseif (null === $monitor['last_pull_at'] || $now->getTimestamp() - $this->time((string) $monitor['last_pull_at'])->getTimestamp() > self::PULL_FRESH_SECONDS) {
            $reason = 'pull_stale';
        } elseif ([] === $row || null === $row['remote_id']) {
            $reason = 'missing';
        } elseif ($row['configuration_fingerprint'] !== $monitor['configuration_fingerprint']) {
            $reason = 'version_changed';
        } elseif ('passed' !== $row['status']) {
            $reason = (string) $row['status'];
        } elseif (true !== $row['quality_passed']) {
            $reason = 'incomplete';
        }
        if (null === $reason) {
            $changed = $this->db->executeStatement('UPDATE ai_quality_monitor SET alert_key = NULL WHERE suite = :suite AND alert_key IS NOT NULL', ['suite' => self::SUITE]);
            if (1 === $changed) {
                $this->logger->info('PaymentIntake nightly quality check recovered.', ['suite' => self::SUITE, 'run_date' => $date]);
            }

            return 'passed';
        }
        $key = $date.':'.$reason;
        $changed = $this->db->executeStatement(
            'UPDATE ai_quality_monitor SET alert_key = :key WHERE suite = :suite AND alert_key IS DISTINCT FROM :key',
            ['suite' => self::SUITE, 'key' => $key],
        );
        if (1 === $changed) {
            $context = ['suite' => self::SUITE, 'run_date' => $date, 'reason' => $reason, 'failures' => $this->failures($row['report'] ?? null)];
            if ('skipped_budget' === $reason) {
                $this->logger->warning('PaymentIntake nightly quality check skipped by budget.', $context);
            } else {
                $this->logger->error('PaymentIntake nightly quality check failed.', $context);
            }
        }

        return $reason;
    }

    private function isEnabled(): bool
    {
        return $this->enabled && $this->access->isEnabled();
    }

    private function activate(): void
    {
        $this->db->executeStatement(
            'INSERT INTO ai_quality_monitor (id, suite, enabled_at) VALUES (:id, :suite, :at) ON CONFLICT (suite) DO NOTHING',
            ['id' => Uuid::uuid7()->toString(), 'suite' => self::SUITE, 'at' => $this->stamp($this->now())],
        );
    }

    private function failure(string $reason, ?int $pullRevision = null): string
    {
        $where = null === $pullRevision ? '' : ' AND pull_revision = :revision';
        $params = ['error' => $reason, 'suite' => self::SUITE];
        if (null !== $pullRevision) {
            $params['revision'] = $pullRevision;
        }
        $this->db->executeStatement('UPDATE ai_quality_monitor SET error_code = :error WHERE suite = :suite'.$where, $params);

        return $reason;
    }

    /** @return array<string, mixed> */
    private function monitor(): array
    {
        return $this->db->fetchAssociative('SELECT * FROM ai_quality_monitor WHERE suite = :suite', ['suite' => self::SUITE]) ?: [];
    }

    /** @return array<string, mixed> */
    private function run(string $date): array
    {
        return $this->db->fetchAssociative('SELECT * FROM ai_quality_run WHERE suite = :suite AND run_date = :date', ['suite' => self::SUITE, 'date' => $date]) ?: [];
    }

    private function saveRun(AiEvalRun $run): void
    {
        $saved = $this->db->executeStatement(
            'INSERT INTO ai_quality_run (id, suite, run_date, trace_id, remote_id, status, configuration_fingerprint, report, quality_passed, remote_updated_at, completed_at)
             VALUES (:id, :suite, :date, :trace, :remote, :status, :fingerprint, :report, :passed, :updated, :completed)
             ON CONFLICT (suite, run_date) DO UPDATE SET remote_id = EXCLUDED.remote_id, status = EXCLUDED.status,
             configuration_fingerprint = EXCLUDED.configuration_fingerprint, report = EXCLUDED.report,
             quality_passed = EXCLUDED.quality_passed, remote_updated_at = EXCLUDED.remote_updated_at, completed_at = EXCLUDED.completed_at
             WHERE (ai_quality_run.remote_id IS NULL OR ai_quality_run.remote_id = EXCLUDED.remote_id)
             AND (ai_quality_run.remote_updated_at IS NULL OR ai_quality_run.remote_updated_at <= EXCLUDED.remote_updated_at)',
            ['id' => Uuid::uuid7()->toString(), 'suite' => self::SUITE, 'date' => $run->runDate->format('Y-m-d'), 'trace' => $run->traceId,
                'remote' => $run->runId, 'status' => $run->status, 'fingerprint' => $run->configurationFingerprint,
                'report' => null === $run->report ? null : json_encode($run->report, \JSON_THROW_ON_ERROR), 'passed' => $run->isPassing(),
                'updated' => $this->stamp($run->updatedAt), 'completed' => null === $run->completedAt ? null : $this->stamp($run->completedAt)],
            ['passed' => ParameterType::BOOLEAN],
        );
        if (0 === $saved) {
            $old = $this->run($run->runDate->format('Y-m-d'));
            if ($old['remote_id'] !== $run->runId) {
                throw ContractViolationException::at('ai-eval-run/v1', '$.run_id', 'daily run identity changed');
            }
            // A late response from an older observation is ignored, never applied backwards.
        }
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Moscow'));
    }

    /** @return list<array<string, string|int>> */
    private function failures(mixed $stored): array
    {
        if (!\is_string($stored)) {
            return [];
        }
        try {
            $report = json_decode($stored, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!\is_array($report) || !\is_array($report['models'] ?? null)) {
            return [];
        }
        $failures = [];
        foreach ($report['models'] as $name => $model) {
            if (!\is_string($name) || !\is_array($model) || !\is_array($model['failures'] ?? null)) {
                continue;
            }
            foreach ($model['failures'] as $failure) {
                if (!\is_array($failure)) {
                    continue;
                }
                $safe = ['model' => $name];
                foreach (['dataset', 'case_id', 'metric', 'numerator', 'denominator', 'verdict'] as $key) {
                    if (\is_string($failure[$key] ?? null) || \is_int($failure[$key] ?? null)) {
                        $safe[$key] = $failure[$key];
                    }
                }
                $failures[] = $safe;
                if (\count($failures) >= 100) {
                    return $failures;
                }
            }
        }

        return $failures;
    }

    private function stamp(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d H:i:s.u');
    }

    private function time(string $at): \DateTimeImmutable
    {
        return new \DateTimeImmutable($at, new \DateTimeZone('Europe/Moscow'));
    }
}
