<?php

namespace App\Entity;

use App\Company\Entity\Company;
use App\Company\Enum\CounterpartyType;
use App\Repository\CounterpartyRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Webmozart\Assert\Assert as WebAssert;

#[ORM\Entity(repositoryClass: CounterpartyRepository::class)]
#[ORM\Table(name: '`counterparty`')]
#[ORM\Index(name: 'idx_counterparty_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_counterparty_company_inn', columns: ['company_id', 'inn'])]
class Counterparty
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Company $company;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name;

    #[ORM\Column(length: 12, nullable: true)]
    #[Assert\Regex(pattern: '/^\d{10}(\d{2})?$/')]
    private ?string $inn = null;

    #[ORM\Column(enumType: CounterpartyType::class)]
    private CounterpartyType $type;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $updatedAt;

    #[ORM\Column(type: 'boolean')]
    private bool $isArchived = false;

    public function __construct(string $id, Company $company, string $name, CounterpartyType $type)
    {
        WebAssert::uuid($id);
        $this->id = $id;
        $this->company = $company;
        $this->name = $name;
        $this->type = $type;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getInn(): ?string
    {
        return $this->inn;
    }

    public function setInn(?string $inn): self
    {
        $this->inn = $inn;

        return $this;
    }

    public function getType(): CounterpartyType
    {
        return $this->type;
    }

    public function setType(CounterpartyType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function isArchived(): bool
    {
        return $this->isArchived;
    }

    public function setIsArchived(bool $isArchived): self
    {
        $this->isArchived = $isArchived;

        return $this;
    }
}
