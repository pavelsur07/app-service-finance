<?php

declare(strict_types=1);

namespace App\Billing\Entity;

use App\Billing\Enum\IntegrationBillingType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Billing\Repository\IntegrationRepository::class)]
#[ORM\Table(name: 'billing_integration')]
#[ORM\UniqueConstraint(name: 'uniq_billing_integration_code', columns: ['code'])]
#[ORM\Index(name: 'idx_billing_integration_is_active', columns: ['is_active'])]
final class Integration
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private string $id;

    #[ORM\Column(type: 'string')]
    private string $code;

    #[ORM\Column(type: 'string')]
    private string $name;

    #[ORM\Column(type: 'string', enumType: IntegrationBillingType::class)]
    private IntegrationBillingType $billingType;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $priceAmount;

    #[ORM\Column(type: 'string', length: 3, nullable: true)]
    private ?string $priceCurrency;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive;

    public function __construct(
        string $id,
        string $code,
        string $name,
        IntegrationBillingType $billingType,
        ?int $priceAmount,
        ?string $priceCurrency,
        bool $isActive,
    ) {
        $this->id = $id;
        $this->code = $code;
        $this->name = $name;
        $this->billingType = $billingType;
        $this->priceAmount = $priceAmount;
        $this->priceCurrency = $priceCurrency;
        $this->isActive = $isActive;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getBillingType(): IntegrationBillingType
    {
        return $this->billingType;
    }

    public function getPriceAmount(): ?int
    {
        return $this->priceAmount;
    }

    public function getPriceCurrency(): ?string
    {
        return $this->priceCurrency;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }
}
