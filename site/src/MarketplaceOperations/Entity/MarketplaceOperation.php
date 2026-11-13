<?php

declare(strict_types=1);

namespace App\MarketplaceOperations\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Каноническая операция (`DATA.md` §5, §9). Только схема: пишет `MarketplaceOperationsFacade`
 * через DBAL, читать будет витрина (этап 5).
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'mo_operation')]
#[ORM\Index(name: 'idx_mo_operation_window', columns: ['company_id', 'channel', 'connection_ref', 'operation_date'])]
class MarketplaceOperation
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'string', length: 32)]
    private string $channel;

    #[ORM\Column(type: 'string', length: 64)]
    private string $connectionRef;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $operationDate;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $listingId;

    #[ORM\Column(type: 'string', length: 128)]
    private string $sourceType;

    #[ORM\Column(type: 'string', length: 64)]
    private string $kind;

    #[ORM\Column(type: 'bigint')]
    private int $amountMinor;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $quantity;

    /** @var list<string> */
    #[ORM\Column(type: 'jsonb')]
    private array $sourceRefs;

    #[ORM\Column(type: 'string', length: 32)]
    private string $rulesVersion;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $allocationKey;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;
}
