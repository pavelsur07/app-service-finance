<?php

declare(strict_types=1);

namespace App\SourceFacts\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Журнал пачек сервиса: `applied` — ревизия стала текущей, `superseded` — пришла не новее
 * текущей и подтверждена без применения, `quarantined` — принять нельзя (причина в `reason`),
 * сервису не подтверждена и придёт снова.
 *
 * Только схема: пишет и читает `SourceFactStore` через DBAL.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'sf_batch')]
#[ORM\UniqueConstraint(name: 'uq_sf_batch_service_batch', columns: ['service', 'batch_id'])]
#[ORM\Index(name: 'idx_sf_batch_status', columns: ['status'])]
class SourceFactBatch
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'string', length: 64)]
    private string $service;

    #[ORM\Column(type: 'string', length: 255)]
    private string $batchId;

    /** null — подключение пачки неизвестно (карантин `unknown_connection`). */
    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $companyId;

    #[ORM\Column(type: 'string', length: 64)]
    private string $connectionRef;

    #[ORM\Column(type: 'string', length: 128)]
    private string $resourceType;

    #[ORM\Column(type: 'string', length: 32)]
    private string $periodKey;

    #[ORM\Column(type: 'integer')]
    private int $revision;

    #[ORM\Column(type: 'string', length: 64)]
    private string $contentHash;

    #[ORM\Column(type: 'string', length: 16)]
    private string $status;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $reason;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $details;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $attempts;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;
}
