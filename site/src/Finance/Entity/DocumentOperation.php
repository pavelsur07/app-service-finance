<?php

declare(strict_types=1);

namespace App\Finance\Entity;

use App\Company\Entity\Counterparty;
use App\Company\Entity\ProjectDirection;
use App\Finance\Repository\DocumentOperationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: DocumentOperationRepository::class)]
#[ORM\Table(name: 'document_operations')]
class DocumentOperation
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Document::class, inversedBy: 'operations')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Document $document = null;

    #[ORM\ManyToOne(targetEntity: PLCategory::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?PLCategory $category = null;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 2)]
    private string $amount;

    #[ORM\ManyToOne(targetEntity: Counterparty::class)]
    private ?Counterparty $counterparty = null;

    #[ORM\ManyToOne(targetEntity: ProjectDirection::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?ProjectDirection $projectDirection = null;

    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $responsibilityCenterId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $comment = null;

    public function __construct(?string $id = null)
    {
        $id = $id ?? Uuid::uuid4()->toString();
        Assert::uuid($id);
        $this->id = $id;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getDocument(): ?Document
    {
        return $this->document;
    }

    public function setDocument(?Document $document): self
    {
        if (null !== $document) {
            $this->assertReferencesBelongTo($document);
        }
        $this->document = $document;
        // Обе стороны связи: операция, привязанная напрямую, без этого не попала бы в коллекцию
        // документа, и Document::setCompany() не проверил бы её справочники.
        if (null !== $document && !$document->getOperations()->contains($this)) {
            $document->addOperation($this);
        }

        return $this;
    }

    public function getCategory(): ?PLCategory
    {
        return $this->category;
    }

    public function setCategory(?PLCategory $category): self
    {
        $this->document?->assertOwnReference('P&L category', $category?->getCompany(), $category?->getId());
        $this->category = $category;

        return $this;
    }

    public function getPlCategory(): ?PLCategory
    {
        return isset($this->category) ? $this->category : null;
    }

    public function setPlCategory(?PLCategory $category): self
    {
        return $this->setCategory($category);
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): self
    {
        $this->amount = $amount;

        return $this;
    }

    public function getCounterparty(): ?Counterparty
    {
        return $this->counterparty;
    }

    public function setCounterparty(?Counterparty $counterparty): self
    {
        $this->document?->assertOwnReference('counterparty', $counterparty?->getCompany(), $counterparty?->getId());
        $this->counterparty = $counterparty;

        return $this;
    }

    public function getProjectDirection(): ?ProjectDirection
    {
        return $this->projectDirection;
    }

    public function setProjectDirection(?ProjectDirection $projectDirection): self
    {
        $this->document?->assertOwnReference('project direction', $projectDirection?->getCompany(), $projectDirection?->getId());
        $this->projectDirection = $projectDirection;

        return $this;
    }

    public function getResponsibilityCenterId(): ?string
    {
        return $this->responsibilityCenterId;
    }

    public function setResponsibilityCenterId(?string $responsibilityCenterId): self
    {
        $this->responsibilityCenterId = $responsibilityCenterId;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): self
    {
        $this->comment = $comment;

        return $this;
    }

    /**
     * Справочники операции — компании документа (инвариант держит Document::assertOwnReference).
     */
    public function assertReferencesBelongTo(Document $document): void
    {
        $document->assertOwnReference('P&L category', $this->category?->getCompany(), $this->category?->getId());
        $document->assertOwnReference('counterparty', $this->counterparty?->getCompany(), $this->counterparty?->getId());
        $document->assertOwnReference('project direction', $this->projectDirection?->getCompany(), $this->projectDirection?->getId());
    }
}
