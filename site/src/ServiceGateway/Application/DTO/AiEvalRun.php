<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\DTO;

final readonly class AiEvalRun
{
    /**
     * @param array<string, mixed>|null $report Only projected technical metadata, never the raw response
     */
    public function __construct(
        public string $runId,
        public string $suite,
        public \DateTimeImmutable $runDate,
        public string $traceId,
        public string $status,
        public int $attempt,
        public string $configurationFingerprint,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public ?\DateTimeImmutable $completedAt,
        public ?string $errorCode,
        public ?array $report,
    ) {
    }

    public function isPassing(): bool
    {
        $report = $this->report;
        if ('passed' !== $this->status || null === $this->completedAt || $this->attempt < 1
            || null === $report || true !== ($report['complete'] ?? null)
            || !\is_int($report['network_calls'] ?? null) || $report['network_calls'] <= 0
            || $this->configurationFingerprint !== ($report['configuration_fingerprint'] ?? null)) {
            return false;
        }
        $models = $report['models'] ?? null;
        $expected = $report['expected_models'] ?? null;
        if (!\is_array($models) || [] === $models || !\is_array($expected) || [] === $expected
            || \count($models) !== \count($expected)) {
            return false;
        }
        foreach ($expected as $name) {
            if (!\is_string($name) || !isset($models[$name]) || !\is_array($models[$name])) {
                return false;
            }
            $model = $models[$name];
            $uncovered = $model['uncovered'] ?? null;
            if ('passed' !== ($model['status'] ?? null) || true !== ($model['complete'] ?? null)
                || !empty($model['failures'])
                || !\is_int($model['network_calls'] ?? null) || $model['network_calls'] <= 0
                || !\is_array($uncovered) || [] === $uncovered) {
                return false;
            }
            foreach ($uncovered as $count) {
                if (0 !== $count) {
                    return false;
                }
            }
        }

        return true;
    }
}
