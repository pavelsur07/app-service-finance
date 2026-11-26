<?php

declare(strict_types=1);

namespace App\OneC\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Принятая часть пакета. Пишется DBAL-ом в ReceivePackageAction
 * (INSERT … ON CONFLICT), маппинг нужен для чтения и гейта схемы.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'onec_package')]
#[ORM\UniqueConstraint(name: 'uniq_onec_package_part', columns: ['connection_id', 'package_id', 'part'])]
#[ORM\Index(name: 'idx_onec_package_connection_received', columns: ['connection_id', 'received_at'])]
class OneCPackage
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    public private(set) string $id;

    #[ORM\ManyToOne(targetEntity: OneCConnection::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public private(set) OneCConnection $connection;

    #[ORM\Column(type: 'guid')]
    public private(set) string $packageId;

    #[ORM\Column]
    public private(set) int $part;

    #[ORM\Column]
    public private(set) int $partsTotal;

    #[ORM\Column(length: 32)]
    public private(set) string $kind;

    #[ORM\Column(type: 'date_immutable')]
    public private(set) \DateTimeImmutable $periodFrom;

    #[ORM\Column(type: 'date_immutable')]
    public private(set) \DateTimeImmutable $periodTo;

    #[ORM\Column]
    public private(set) bool $includeOpenOutsidePeriod;

    #[ORM\Column(length: 255)]
    public private(set) string $processorVersion;

    /** @var array<string, string> */
    #[ORM\Column(type: 'jsonb')]
    public private(set) array $versions;

    /** @var list<mixed> */
    #[ORM\Column(type: 'jsonb')]
    public private(set) array $warnings;

    #[ORM\Column]
    public private(set) int $documentCount;

    #[ORM\Column(length: 255)]
    public private(set) string $storagePath;

    #[ORM\Column(length: 64)]
    public private(set) string $sha256;

    #[ORM\Column]
    public private(set) int $byteSize;

    #[ORM\Column(length: 16)]
    public private(set) string $status;

    #[ORM\Column]
    public private(set) \DateTimeImmutable $receivedAt;
}
