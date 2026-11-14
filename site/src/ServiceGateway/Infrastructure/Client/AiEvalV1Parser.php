<?php

declare(strict_types=1);

namespace App\ServiceGateway\Infrastructure\Client;

use App\ServiceGateway\Application\DTO\AiEvalAccepted;
use App\ServiceGateway\Application\DTO\AiEvalLatest;
use App\ServiceGateway\Application\DTO\AiEvalRun;
use App\ServiceGateway\Exception\ContractViolationException;

/**
 * ai-eval-run/v1: strict envelopes and an allowlisted projection of the opaque report.
 * JSON must be decoded into objects to distinguish {} from [].
 *
 * @phpstan-type SafeEvalFailure array{dataset: string, case_id?: string, metric?: string, numerator?: int, denominator?: int, verdict?: string}
 */
final readonly class AiEvalV1Parser
{
    public const CONTRACT = 'ai-eval-run/v1';
    private const STATES = ['queued', 'running', 'passed', 'failed', 'unavailable', 'incomplete', 'skipped_budget'];
    private const UUID = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D';
    private const HASH = '/^[0-9a-f]{64}$/D';
    private const CODE = '/^[a-z][a-z0-9_]{0,127}$/D';
    private const DATASET = '/^(?:[a-z][a-z0-9_]{0,127}|reviewed:[a-z0-9][a-z0-9_.-]{0,63})$/D';
    private const CASE_ID = '/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,95}$/D';
    private const MODEL = '/^[a-zA-Z0-9_-]+\/[a-zA-Z0-9_.:\/-]{1,192}$/D';
    private const MAX_FAILURES_PER_MODEL = 100;

    public function parseAccepted(mixed $value): AiEvalAccepted
    {
        $data = $this->object($value, '$', ['contract_version', 'run_id', 'status', 'duplicate', 'configuration_fingerprint']);
        $this->version($data['contract_version']);

        return new AiEvalAccepted(
            $this->uuid($data['run_id'], '$.run_id'),
            $this->state($data['status'], '$.status'),
            $this->boolean($data['duplicate'], '$.duplicate'),
            $this->matching($data['configuration_fingerprint'], '$.configuration_fingerprint', self::HASH),
        );
    }

    public function parseLatest(mixed $value): AiEvalLatest
    {
        $data = $this->object($value, '$', ['enabled', 'configuration_fingerprint', 'run']);

        return new AiEvalLatest(
            $this->boolean($data['enabled'], '$.enabled'),
            null === $data['configuration_fingerprint'] ? null : $this->matching($data['configuration_fingerprint'], '$.configuration_fingerprint', self::HASH),
            null === $data['run'] ? null : $this->parseRun($data['run']),
        );
    }

    public function parseRun(mixed $value): AiEvalRun
    {
        $data = $this->object($value, '$', [
            'contract_version', 'run_id', 'suite', 'run_date', 'trace_id', 'status', 'attempt',
            'configuration_fingerprint', 'created_at', 'updated_at', 'completed_at', 'error_code', 'report',
        ]);
        $this->version($data['contract_version']);
        if ('payment_intake' !== $data['suite']) {
            throw $this->violation('$.suite', 'unknown suite');
        }

        return new AiEvalRun(
            runId: $this->uuid($data['run_id'], '$.run_id'),
            suite: 'payment_intake',
            runDate: $this->date($data['run_date'], '$.run_date', true),
            traceId: $this->uuid($data['trace_id'], '$.trace_id'),
            status: $this->state($data['status'], '$.status'),
            attempt: $this->count($data['attempt'], '$.attempt'),
            configurationFingerprint: $this->matching($data['configuration_fingerprint'], '$.configuration_fingerprint', self::HASH),
            createdAt: $this->date($data['created_at'], '$.created_at'),
            updatedAt: $this->date($data['updated_at'], '$.updated_at'),
            completedAt: null === $data['completed_at'] ? null : $this->date($data['completed_at'], '$.completed_at'),
            errorCode: null === $data['error_code'] ? null : $this->matching($data['error_code'], '$.error_code', self::CODE),
            report: $this->report($data['report']),
        );
    }

    /** @return array<string, mixed>|null */
    private function report(mixed $value): ?array
    {
        if (null === $value) {
            return null;
        }
        $data = $this->object($value, '$.report');
        $safe = [
            'complete' => \array_key_exists('complete', $data) ? $this->boolean($data['complete'], '$.report.complete') : false,
            'network_calls' => isset($data['network_calls']) ? $this->count($data['network_calls'], '$.report.network_calls') : null,
            'configuration_fingerprint' => isset($data['configuration_fingerprint']) ? $this->matching($data['configuration_fingerprint'], '$.report.configuration_fingerprint', self::HASH) : '',
            'models' => [],
            'expected_models' => [],
        ];
        if (\array_key_exists('models', $data)) {
            foreach ($this->object($data['models'], '$.report.models') as $name => $raw) {
                $name = $this->matching($name, '$.report.models', self::MODEL);
                $model = $this->object($raw, '$.report.models[]');
                $uncovered = [];
                if (\array_key_exists('uncovered', $model)) {
                    foreach ($this->object($model['uncovered'], '$.report.models[].uncovered') as $dataset => $count) {
                        $dataset = $this->matching($dataset, '$.report.models[].uncovered', self::DATASET);
                        $uncovered[$dataset] = $this->count($count, '$.report.models[].uncovered[]');
                    }
                }
                $safe['models'][$name] = [
                    'status' => \array_key_exists('status', $model) ? $this->state($model['status'], '$.report.models[].status') : 'incomplete',
                    'complete' => \array_key_exists('complete', $model) ? $this->boolean($model['complete'], '$.report.models[].complete') : false,
                    'network_calls' => isset($model['network_calls']) ? $this->count($model['network_calls'], '$.report.models[].network_calls') : null,
                    'uncovered' => $uncovered,
                    'failures' => $this->failures($model['datasets'] ?? null),
                ];
            }
        }
        // Only names of configured models are retained, never code hashes, prices or runtime metadata.
        $configuration = $data['configuration'] ?? null;
        $route = $configuration instanceof \stdClass ? ($configuration->route ?? null) : null;
        $expected = $route instanceof \stdClass ? ($route->models ?? null) : null;
        if (\is_array($expected) && array_is_list($expected)) {
            foreach ($expected as $model) {
                if (!$model instanceof \stdClass) {
                    throw $this->violation('$.report.configuration.route.models[]', 'must be an object');
                }
                $safe['expected_models'][] = $this->matching($model->model ?? null, '$.report.configuration.route.models[].model', self::MODEL);
            }
            if (\count(array_unique($safe['expected_models'])) !== \count($safe['expected_models'])) {
                throw $this->violation('$.report.configuration.route.models', 'must contain distinct models');
            }
        }

        return $safe;
    }

    /** @return list<SafeEvalFailure> */
    private function failures(mixed $datasets): array
    {
        $failures = [];
        if (null === $datasets) {
            return $failures;
        }
        foreach ($this->object($datasets, '$.report.models[].datasets') as $name => $raw) {
            if (\count($failures) >= self::MAX_FAILURES_PER_MODEL) {
                break;
            }
            $name = $this->matching($name, '$.report.models[].datasets', self::DATASET);
            $dataset = $this->object($raw, '$.report.models[].datasets[]');
            $this->metricFailures($failures, $dataset['metrics'] ?? null, $name);
            $cases = $dataset['cases'] ?? [];
            if (!\is_array($cases) || !array_is_list($cases)) {
                throw $this->violation('$.report.models[].datasets[].cases', 'must be an array');
            }
            foreach ($cases as $row) {
                if (\count($failures) >= self::MAX_FAILURES_PER_MODEL) {
                    break;
                }
                $case = $this->object($row, '$.report.models[].datasets[].cases[]');
                $failed = \in_array($case['verdict'] ?? null, ['failed', 'rejected'], true);
                if (!$failed && !isset($case['metrics'])) {
                    continue;
                }
                $id = $this->matching($case['id'] ?? null, '$.report.models[].datasets[].cases[].id', self::CASE_ID);
                if ($failed) {
                    $failures[] = ['dataset' => $name, 'case_id' => $id, 'verdict' => $case['verdict']];
                }
                $this->metricFailures($failures, $case['metrics'] ?? null, $name, $id);
            }
        }

        return $failures;
    }

    /** @param list<SafeEvalFailure> $failures */
    private function metricFailures(array &$failures, mixed $metrics, string $dataset, ?string $caseId = null): void
    {
        if (null === $metrics) {
            return;
        }
        foreach ($this->object($metrics, '$.report.models[].datasets[].metrics') as $name => $raw) {
            if (\count($failures) >= self::MAX_FAILURES_PER_MODEL) {
                return;
            }
            // Scorer also emits nullable metrics; only numeric numerator/denominator pairs are actionable.
            if (!$raw instanceof \stdClass || !isset($raw->numerator, $raw->denominator)) {
                continue;
            }
            $numerator = $this->count($raw->numerator, '$.report.models[].datasets[].metrics[].numerator');
            $denominator = $this->count($raw->denominator, '$.report.models[].datasets[].metrics[].denominator');
            if ($numerator >= $denominator) {
                continue;
            }
            $metric = $this->matching($name, '$.report.models[].datasets[].metrics', self::CODE);
            $failure = ['dataset' => $dataset];
            if (null !== $caseId) {
                $failure['case_id'] = $caseId;
            }
            $failures[] = $failure + ['metric' => $metric, 'numerator' => $numerator, 'denominator' => $denominator];
        }
    }

    /**
     * @param list<string>|null $keys
     *
     * @return array<string, mixed>
     */
    private function object(mixed $value, string $path, ?array $keys = null): array
    {
        if (!$value instanceof \stdClass) {
            throw $this->violation($path, 'must be an object');
        }
        $data = get_object_vars($value);
        if (null !== $keys && ([] !== array_diff($keys, array_keys($data)) || [] !== array_diff(array_keys($data), $keys))) {
            // Unknown keys may themselves contain user data, so never echo them.
            throw $this->violation($path, 'unexpected or missing fields');
        }

        return $data;
    }

    private function version(mixed $value): void
    {
        if ('v1' !== $value) {
            throw $this->violation('$.contract_version', 'unknown version');
        }
    }

    private function matching(mixed $value, string $path, string $pattern): string
    {
        if (!\is_string($value) || 1 !== preg_match($pattern, $value)) {
            throw $this->violation($path, 'has an invalid format');
        }

        return $value;
    }

    private function uuid(mixed $value, string $path): string
    {
        return strtolower($this->matching($value, $path, self::UUID));
    }

    private function state(mixed $value, string $path): string
    {
        if (!\is_string($value) || !\in_array($value, self::STATES, true)) {
            throw $this->violation($path, 'unknown status');
        }

        return $value;
    }

    private function boolean(mixed $value, string $path): bool
    {
        if (!\is_bool($value)) {
            throw $this->violation($path, 'must be a boolean');
        }

        return $value;
    }

    private function count(mixed $value, string $path): int
    {
        if (!\is_int($value) || $value < 0) {
            throw $this->violation($path, 'must be a non-negative integer');
        }

        return $value;
    }

    private function date(mixed $value, string $path, bool $dateOnly = false): \DateTimeImmutable
    {
        $pattern = $dateOnly ? '/^\d{4}-\d{2}-\d{2}$/D' : '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D';
        $raw = $this->matching($value, $path, $pattern);
        $format = $dateOnly ? '!Y-m-d' : (str_contains($raw, '.') ? '!Y-m-d\TH:i:s.uP' : '!Y-m-d\TH:i:sP');
        $parsed = \DateTimeImmutable::createFromFormat($format, $raw, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (false === $parsed || (false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw $this->violation($path, 'must be a valid date');
        }

        return $parsed;
    }

    private function violation(string $path, string $reason): ContractViolationException
    {
        return ContractViolationException::at(self::CONTRACT, $path, $reason);
    }
}
