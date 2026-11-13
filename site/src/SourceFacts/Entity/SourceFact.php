<?php

declare(strict_types=1);

namespace App\SourceFacts\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Версия записи источника. Действует в ревизии N периода, если
 * `firstRevision <= N <= lastSeenRevision`; строки не удаляются и не перезаписываются.
 * `payload` — запись как её отдал сервис; модуль её не разбирает.
 *
 * Только схема: пишет и читает `SourceFactStore` через DBAL.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'sf_fact')]
#[ORM\UniqueConstraint(name: 'uq_sf_fact_version', columns: ['company_id', 'connection_ref', 'resource_type', 'period_key', 'source_key', 'payload_hash'])]
class SourceFact
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'string', length: 64)]
    private string $connectionRef;

    #[ORM\Column(type: 'string', length: 128)]
    private string $resourceType;

    #[ORM\Column(type: 'string', length: 32)]
    private string $periodKey;

    #[ORM\Column(type: 'string', length: 255)]
    private string $sourceKey;

    #[ORM\Column(type: 'string', length: 64)]
    private string $payloadHash;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'jsonb')]
    private array $payload;

    #[ORM\Column(type: 'integer')]
    private int $firstRevision;

    #[ORM\Column(type: 'integer')]
    private int $lastSeenRevision;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;
}
