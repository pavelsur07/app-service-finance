<?php

namespace App\Entity;

use App\Enum\DocumentStatus;
use App\Enum\DocumentType;
use App\Repository\DocumentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: DocumentRepository::class)]
#[ORM\Table(name: 'documents')]
class Document
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: CashTransaction::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?CashTransaction $cashTransaction = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $number = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: Counterparty::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Counterparty $counterparty = null;

    #[ORM\Column(enumType: DocumentType::class, options: ['default' => DocumentType::OTHER->value])]
    private DocumentType $type = DocumentType::OTHER;

    #[ORM\Column(enumType: DocumentStatus::class, options: ['default' => DocumentStatus::ACTIVE->value])]
    private DocumentStatus $status = DocumentStatus::ACTIVE;

    #[ORM\OneToMany(mappedBy: 'document', targetEntity: DocumentOperation::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $operations;

    public function __construct(string $id, Company $company)
    {
        Assert::uuid($id);
        $this->id = $id;
        $this->company = $company;
        $this->date = new \DateTimeImmutable();
        $this->operations = new ArrayCollection();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function setCompany(Company $company): self
    {
        $this->company = $company;

        return $this;
    }

    public function getCashTransaction(): ?CashTransaction
    {
        return $this->cashTransaction;
    }

    public function setCashTransaction(?CashTransaction $cashTransaction): self
    {
        $this->cashTransaction = $cashTransaction;

        return $this;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): self
    {
        $this->date = $date;

        return $this;
    }

    public function getNumber(): ?string
    {
        return $this->number;
    }

    public function setNumber(?string $number): self
    {
        $this->number = $number;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

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

    public function getType(): DocumentType
    {
        return $this->type;
    }

    public function setType(DocumentType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getStatus(): DocumentStatus
    {
        return $this->status;
    }

    public function setStatus(DocumentStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    /** @return Collection<int, DocumentOperation> */
    public function getOperations(): Collection
    {
        return $this->operations;
    }

    public function addOperation(DocumentOperation $op): self
    {
        if (!$this->operations->contains($op)) {
            $this->operations->add($op);
            $op->setDocument($this);
        }

        return $this;
    }

    public function removeOperation(DocumentOperation $op): self
    {
        if ($this->operations->removeElement($op)) {
            if ($op->getDocument() === $this) {
                $op->setDocument(null);
            }
        }

        return $this;
    }

    public function getTotalAmount(): float
    {
        $total = 0.0;

        foreach ($this->operations as $operation) {
            if ($operation instanceof DocumentOperation) {
                $total += (float) $operation->getAmount();
            }
        }

        return $total;
    }
}
