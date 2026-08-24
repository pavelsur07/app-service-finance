<?php

declare(strict_types=1);

namespace App\Company\Entity;

use App\Company\Enum\CompanyTaxSystem;
use App\Shared\Domain\ValueObject\Money;
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

    #[ORM\Column(nullable: true, enumType: CompanyTaxSystem::class)]
    private ?CompanyTaxSystem $taxSystem = null;

    #[ORM\Embedded(class: Money::class, columnPrefix: 'minimum_balance_')]
    private Money $minimumBalance;

    public function __construct(string $id, User $user)
    {
        Assert::uuid($id);
        $this->id = $id;
        $this->user = $user;
        $this->minimumBalance = Money::fromMinor(0, 'RUB');
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

    public function getMinimumBalance(): Money
    {
        return $this->minimumBalance;
    }

    public function setMinimumBalance(Money $minimumBalance): self
    {
        if ($minimumBalance->isNegative()) {
            throw new \DomainException('Минимальный остаток не может быть отрицательным.');
        }

        $this->minimumBalance = $minimumBalance;

        return $this;
    }
}
