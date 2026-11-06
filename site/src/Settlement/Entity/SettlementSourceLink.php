<?php

declare(strict_types=1);

namespace App\Settlement\Entity;

use App\Settlement\Enum\SettlementSourceType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: 'settlement_source_links')]
#[ORM\UniqueConstraint(name: 'uniq_settlement_source_links_company_id', columns: ['company_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_settlement_source', columns: ['company_id', 'source_type', 'source_id'])]
class SettlementSourceLink
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    public function __construct(
        #[ORM\Column(type: Types::GUID)]
        private string $companyId,
        #[ORM\Column(enumType: SettlementSourceType::class)]
        private SettlementSourceType $sourceType,
        #[ORM\Column(type: Types::GUID)]
        private string $sourceId,
        ?string $id = null,
    ) {
        $this->id = $id ?? Uuid::uuid7()->toString();
        Assert::uuid($this->id);
        Assert::uuid($companyId);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getSourceType(): SettlementSourceType
    {
        return $this->sourceType;
    }

    public function getSourceId(): string
    {
        return $this->sourceId;
    }
    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $activeDocumentId = null;
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastReversalDate = null;

    public function getActiveDocumentId(): ?string
    {
        return $this->activeDocumentId;
    }

    public function getLastReversalDate(): ?\DateTimeImmutable
    {
        return $this->lastReversalDate;
    }

    public function activate(string $documentId): void
    {
        Assert::uuid($documentId);
        if (null !== $this->activeDocumentId) {
            throw new \DomainException('Источник уже участвует во взаиморасчётах.');
        }
        $this->activeDocumentId = $documentId;
    }

    public function deactivate(\DateTimeImmutable $date): void
    {
        $this->activeDocumentId = null;
        $this->lastReversalDate = $date;
    }
}
