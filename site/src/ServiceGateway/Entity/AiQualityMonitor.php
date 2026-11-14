<?php

declare(strict_types=1);

namespace App\ServiceGateway\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

/** Global technical monitor; no company or financial records. Written through DBAL by AiQualityAction. */
#[ORM\Entity]
#[ORM\Table(name: 'ai_quality_monitor')]
#[ORM\UniqueConstraint(name: 'uniq_ai_quality_monitor_suite', columns: ['suite'])]
class AiQualityMonitor
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(length: 64)]
    private string $suite = 'payment_intake';

    #[ORM\Column(type: 'datetime_immutable_us')]
    private \DateTimeImmutable $enabledAt;

    #[ORM\Column(type: 'datetime_immutable_us', nullable: true)]
    private ?\DateTimeImmutable $lastPullAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $configurationFingerprint = null;

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $remoteEnabled = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $errorCode = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $pullRevision = 0;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $alertKey = null;

    public function __construct(\DateTimeImmutable $enabledAt)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->enabledAt = $enabledAt;
    }

    public function getId(): string
    {
        return $this->id;
    }
}
