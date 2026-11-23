<?php

declare(strict_types=1);

namespace App\MarketplaceOzon\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Решение по коду Ozon (`source_type`): вид канона или `pending` — ждёт администратора.
 * Только схема: пишет `OzonTranslationStore` через DBAL. Коды без строки берут вид из
 * `OzonAccrualTypeSeed`.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'moz_source_type')]
class OzonSourceType
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 128)]
    private string $sourceType;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $kind;

    #[ORM\Column(type: 'string', length: 16)]
    private string $status;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'jsonb', nullable: true)]
    private ?array $aiDraft;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $firstSeenCompanyId;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $firstSeenDate;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $decidedBy;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $decidedAt;
}
