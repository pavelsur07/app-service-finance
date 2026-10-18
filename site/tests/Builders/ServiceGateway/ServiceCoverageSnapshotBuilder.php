<?php

declare(strict_types=1);

namespace App\Tests\Builders\ServiceGateway;

use App\ServiceGateway\Entity\ServiceCoverageSnapshot;

final class ServiceCoverageSnapshotBuilder
{
    private string $service = ServiceCoverageRowBuilder::DEFAULT_SERVICE;
    private \DateTimeImmutable $generatedAt;
    private \DateTimeImmutable $refreshedAt;
    private \DateTimeImmutable $periodFrom;
    private \DateTimeImmutable $periodTo;
    private int $cabinetsTotal = 4;

    private function __construct()
    {
        $this->generatedAt = new \DateTimeImmutable('2026-10-18 09:00:00');
        $this->refreshedAt = new \DateTimeImmutable('2026-10-18 09:00:05');
        $this->periodFrom = new \DateTimeImmutable('2026-08-01');
        $this->periodTo = new \DateTimeImmutable('2026-10-31');
    }

    public static function aSnapshot(): self
    {
        return new self();
    }

    public function withService(string $service): self
    {
        $clone = clone $this;
        $clone->service = $service;

        return $clone;
    }

    public function withRefreshedAt(\DateTimeImmutable $refreshedAt): self
    {
        $clone = clone $this;
        $clone->refreshedAt = $refreshedAt;

        return $clone;
    }

    public function withCabinetsTotal(int $cabinetsTotal): self
    {
        $clone = clone $this;
        $clone->cabinetsTotal = $cabinetsTotal;

        return $clone;
    }

    public function build(): ServiceCoverageSnapshot
    {
        return new ServiceCoverageSnapshot($this->service, $this->generatedAt, $this->refreshedAt, $this->periodFrom, $this->periodTo, $this->cabinetsTotal);
    }
}
