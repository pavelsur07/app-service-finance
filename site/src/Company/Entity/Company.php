<?php

namespace App\Company\Entity;

use App\Enum\CompanyTaxSystem;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: '`companies`')]
class Company
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $inn = null;

    #[ORM\ManyToOne(inversedBy: 'companies')]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $financeLockBefore = null;

    #[ORM\Column(enumType: CompanyTaxSystem::class, nullable: true)]
    private ?CompanyTaxSystem $taxSystem = null;

    public function __construct(string $id, User $user)
    {
        Assert::uuid($id);
        $this->id = $id;
        $this->user = $user;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getName(): ?string
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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getFinanceLockBefore(): ?\DateTimeImmutable
    {
        return $this->financeLockBefore;
    }

    public function setFinanceLockBefore(?\DateTimeImmutable $date): self
    {
        $this->financeLockBefore = $date ? $date->setTime(0, 0) : null;

        return $this;
    }

    public function getTaxSystem(): ?CompanyTaxSystem
    {
        return $this->taxSystem;
    }

    public function setTaxSystem(?CompanyTaxSystem $taxSystem): self
    {
        $this->taxSystem = $taxSystem;

        return $this;
    }
}
