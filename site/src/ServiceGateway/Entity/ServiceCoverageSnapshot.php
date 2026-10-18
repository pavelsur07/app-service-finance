<?php

declare(strict_types=1);

namespace App\ServiceGateway\Entity;

use App\ServiceGateway\Repository\ServiceCoverageSnapshotRepository;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Последний снимок покрытия сервиса: одна строка на сервис. Хранит «данные
 * на» и окно отдельно от строк, чтобы они были и при пустом списке строк.
 * Сводка без кабинетов и компаний — компании не принадлежит.
 */
#[ORM\Entity(repositoryClass: ServiceCoverageSnapshotRepository::class)]
#[ORM\Table(name: 'service_coverage_snapshot')]
#[ORM\UniqueConstraint(name: 'uq_service_coverage_snapshot_service', columns: ['service'])]
class ServiceCoverageSnapshot
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'string', length: 64)]
    private string $service;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $generatedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $refreshedAt;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $periodFrom;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $periodTo;

    #[ORM\Column(type: 'integer')]
    private int $cabinetsTotal;

    public function __construct(
        string $service,
        \DateTimeImmutable $generatedAt,
        \DateTimeImmutable $refreshedAt,
        \DateTimeImmutable $periodFrom,
        \DateTimeImmutable $periodTo,
        int $cabinetsTotal,
        ?string $id = null,
    ) {
        $id ??= Uuid::uuid7()->toString();
        Assert::uuid($id);
        Assert::stringNotEmpty($service);
        Assert::maxLength($service, 64);

        $this->id = $id;
        $this->service = $service;
        $this->replace($generatedAt, $refreshedAt, $periodFrom, $periodTo, $cabinetsTotal);
    }

    public function replace(
        \DateTimeImmutable $generatedAt,
        \DateTimeImmutable $refreshedAt,
        \DateTimeImmutable $periodFrom,
        \DateTimeImmutable $periodTo,
        int $cabinetsTotal,
    ): void {
        Assert::greaterThanEq($cabinetsTotal, 0);
        Assert::true($periodFrom <= $periodTo, 'Coverage period must not be reversed.');

        $this->generatedAt = $generatedAt;
        $this->refreshedAt = $refreshedAt;
        $this->periodFrom = $periodFrom;
        $this->periodTo = $periodTo;
        $this->cabinetsTotal = $cabinetsTotal;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getService(): string
    {
        return $this->service;
    }

    public function getGeneratedAt(): \DateTimeImmutable
    {
        return $this->generatedAt;
    }

    public function getRefreshedAt(): \DateTimeImmutable
    {
        return $this->refreshedAt;
    }

    public function getPeriodFrom(): \DateTimeImmutable
    {
        return $this->periodFrom;
    }

    public function getPeriodTo(): \DateTimeImmutable
    {
        return $this->periodTo;
    }

    public function getCabinetsTotal(): int
    {
        return $this->cabinetsTotal;
    }
}
