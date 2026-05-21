<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

final readonly class WbFinancialReportFirstAvailableResolverResult
{
    private function __construct(
        private ?\DateTimeImmutable $startDate,
        private bool $needsRetry,
        private ?string $phase,
        private ?\DateTimeImmutable $nextProbeFrom,
        private ?\DateTimeImmutable $nextProbeTo,
        private ?int $retryAfterSeconds,
    ) {}

    public static function withStartDate(\DateTimeImmutable $startDate): self
    {
        return new self($startDate->setTime(0, 0, 0), false, null, null, null, null);
    }

    public static function noData(): self
    {
        return new self(null, false, null, null, null, null);
    }

    public static function incomplete(string $phase, \DateTimeImmutable $nextProbeFrom, \DateTimeImmutable $nextProbeTo, ?int $retryAfterSeconds = null): self
    {
        return new self(null, true, $phase, $nextProbeFrom->setTime(0, 0, 0), $nextProbeTo->setTime(0, 0, 0), $retryAfterSeconds);
    }

    public function hasData(): bool { return null !== $this->startDate; }
    public function needsRetry(): bool { return $this->needsRetry; }
    public function getStartDate(): ?\DateTimeImmutable { return $this->startDate; }
    public function getPhase(): ?string { return $this->phase; }
    public function getNextProbeFrom(): ?\DateTimeImmutable { return $this->nextProbeFrom; }
    public function getNextProbeTo(): ?\DateTimeImmutable { return $this->nextProbeTo; }
    public function getRetryAfterSeconds(): ?int { return $this->retryAfterSeconds; }
}
