<?php

namespace App\Entity;

use App\Entity\Ozon\OzonProduct;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $wildberriesApiKey = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ozonSellerId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ozonApiKey = null;

    #[ORM\ManyToOne(inversedBy: 'companies')]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $financeLockBefore = null;

    #[ORM\OneToMany(targetEntity: OzonProduct::class, mappedBy: 'company', orphanRemoval: true)]
    private Collection $ozonProducts;

    public function __construct(string $id, User $user)
    {
        Assert::uuid($id);
        $this->id = $id;
        $this->user = $user;
        $this->ozonProducts = new ArrayCollection();
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

    public function getWildberriesApiKey(): ?string
    {
        return $this->wildberriesApiKey;
    }

    public function setWildberriesApiKey(?string $wildberriesApiKey): self
    {
        $this->wildberriesApiKey = $wildberriesApiKey;

        return $this;
    }

    public function getOzonSellerId(): ?string
    {
        return $this->ozonSellerId;
    }

    public function setOzonSellerId(?string $ozonSellerId): self
    {
        $this->ozonSellerId = $ozonSellerId;

        return $this;
    }

    public function getOzonApiKey(): ?string
    {
        return $this->ozonApiKey;
    }

    public function setOzonApiKey(?string $ozonApiKey): self
    {
        $this->ozonApiKey = $ozonApiKey;

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

    public function getOzonProducts(): Collection
    {
        return $this->ozonProducts;
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
}
