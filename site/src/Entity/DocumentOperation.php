<?php

namespace App\Entity;

use App\Repository\DocumentOperationRepository;
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
        $this->document = $document;

        return $this;
    }

    public function getCategory(): ?PLCategory
    {
        return $this->category;
    }

    public function setCategory(?PLCategory $category): self
    {
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
        $this->counterparty = $counterparty;

        return $this;
    }

    public function getProjectDirection(): ?ProjectDirection
    {
        return $this->projectDirection;
    }

    public function setProjectDirection(?ProjectDirection $projectDirection): self
    {
        $this->projectDirection = $projectDirection;

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
}
