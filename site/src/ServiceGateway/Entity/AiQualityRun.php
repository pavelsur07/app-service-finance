<?php

declare(strict_types=1);

namespace App\ServiceGateway\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

/** Daily technical read model. The Python service owns execution and the paid-call ledger. */
#[ORM\Entity]
#[ORM\Table(name: 'ai_quality_run')]
#[ORM\UniqueConstraint(name: 'uniq_ai_quality_run_day', columns: ['suite', 'run_date'])]
class AiQualityRun
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(length: 64)]
    private string $suite = 'payment_intake';

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $runDate;

    #[ORM\Column(type: 'guid')]
    private string $traceId;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $remoteId = null;

    #[ORM\Column(length: 32)]
    private string $status = 'request_pending';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $configurationFingerprint = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSONB, nullable: true)]
    private ?array $report = null;

    #[ORM\Column(type: 'boolean')]
    private bool $qualityPassed = false;

    #[ORM\Column(type: 'datetime_immutable_us', nullable: true)]
    private ?\DateTimeImmutable $remoteUpdatedAt = null;

    #[ORM\Column(type: 'datetime_immutable_us', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(\DateTimeImmutable $runDate)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->traceId = Uuid::uuid7()->toString();
        $this->runDate = $runDate;
    }

    public function getId(): string
    {
        return $this->id;
    }
}
