<?php

declare(strict_types=1);

namespace App\ServiceGateway\Entity;

use App\ServiceGateway\Repository\ServiceCoverageRowRepository;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Строка витрины покрытия: ресурс сервиса × период → число кабинетов по
 * статусам (контракт service-coverage/v1). Сводка без кабинетов и компаний.
 */
#[ORM\Entity(repositoryClass: ServiceCoverageRowRepository::class)]
#[ORM\Table(name: 'service_coverage_row')]
#[ORM\UniqueConstraint(name: 'uq_service_coverage_row_key', columns: ['service', 'resource_type', 'period_key'])]
class ServiceCoverageRow
{
    private const PERIOD_KEY = '/^(\d{4}-\d{2}-\d{2}|\d{4}-\d{2}|ref)$/D';

    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'string', length: 64)]
    private string $service;

    #[ORM\Column(type: 'string', length: 128)]
    private string $resourceType;

    #[ORM\Column(type: 'string', length: 10)]
    private string $periodKey;

    #[ORM\Column(type: 'integer')]
    private int $cabinetsExpected;

    #[ORM\Column(type: 'integer')]
    private int $done;

    #[ORM\Column(type: 'integer')]
    private int $running;

    #[ORM\Column(type: 'integer')]
    private int $pending;

    #[ORM\Column(type: 'integer')]
    private int $notReady;

    #[ORM\Column(type: 'integer')]
    private int $failedRetryable;

    #[ORM\Column(type: 'integer')]
    private int $failedPermanent;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastSuccessAt;

    public function __construct(
        string $service,
        string $resourceType,
        string $periodKey,
        int $cabinetsExpected,
        int $done,
        int $running,
        int $pending,
        int $notReady,
        int $failedRetryable,
        int $failedPermanent,
        ?\DateTimeImmutable $lastSuccessAt,
        ?string $id = null,
    ) {
        $id ??= Uuid::uuid7()->toString();
        Assert::uuid($id);
        Assert::stringNotEmpty($service);
        Assert::maxLength($service, 64);
        Assert::stringNotEmpty($resourceType);
        Assert::maxLength($resourceType, 128);
        Assert::regex($periodKey, self::PERIOD_KEY);
        foreach ([$cabinetsExpected, $done, $running, $pending, $notReady, $failedRetryable, $failedPermanent] as $count) {
            Assert::greaterThanEq($count, 0);
        }

        $this->id = $id;
        $this->service = $service;
        $this->resourceType = $resourceType;
        $this->periodKey = $periodKey;
        $this->cabinetsExpected = $cabinetsExpected;
        $this->done = $done;
        $this->running = $running;
        $this->pending = $pending;
        $this->notReady = $notReady;
        $this->failedRetryable = $failedRetryable;
        $this->failedPermanent = $failedPermanent;
        $this->lastSuccessAt = $lastSuccessAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getService(): string
    {
        return $this->service;
    }

    public function getResourceType(): string
    {
        return $this->resourceType;
    }

    public function getPeriodKey(): string
    {
        return $this->periodKey;
    }

    public function getCabinetsExpected(): int
    {
        return $this->cabinetsExpected;
    }

    public function getDone(): int
    {
        return $this->done;
    }

    public function getFailedPermanent(): int
    {
        return $this->failedPermanent;
    }

    public function getLastSuccessAt(): ?\DateTimeImmutable
    {
        return $this->lastSuccessAt;
    }
}
