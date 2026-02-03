<?php

declare(strict_types=1);

namespace App\Tests\Builders\Deals;

use App\Company\Entity\Company;
use App\Deals\Entity\ChargeType;
use App\Tests\Builders\Company\CompanyBuilder;

final class ChargeTypeBuilder
{
    public const DEFAULT_CHARGE_TYPE_ID = '55555555-5555-5555-5555-555555555555';
    public const DEFAULT_CODE = 'DELIVERY';
    public const DEFAULT_NAME = 'Delivery';
    public const DEFAULT_DATE_TIME = '2024-02-01 00:00:00+00:00';

    private string $id;
    private Company $company;
    private string $code;
    private string $name;
    private bool $isActive;

    private function __construct()
    {
        $this->id = self::DEFAULT_CHARGE_TYPE_ID;
        $this->company = CompanyBuilder::aCompany()->build();
        $this->code = self::DEFAULT_CODE;
        $this->name = self::DEFAULT_NAME;
        $this->isActive = true;
    }

    public static function aChargeType(): self
    {
        return new self();
    }

    public function withId(string $id): self
    {
        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    public function forCompany(Company $company): self
    {
        $clone = clone $this;
        $clone->company = $company;

        return $clone;
    }

    public function withCode(string $code): self
    {
        $clone = clone $this;
        $clone->code = $code;

        return $clone;
    }

    public function withName(string $name): self
    {
        $clone = clone $this;
        $clone->name = $name;

        return $clone;
    }

    public function inactive(): self
    {
        $clone = clone $this;
        $clone->isActive = false;

        return $clone;
    }

    public function build(): ChargeType
    {
        $chargeType = new ChargeType(
            $this->id,
            $this->company,
            $this->code,
            $this->name,
        );

        $chargeType->setIsActive($this->isActive);

        $createdAt = new \DateTimeImmutable(self::DEFAULT_DATE_TIME);
        $chargeType->setCreatedAt($createdAt);
        $chargeType->setUpdatedAt($createdAt);

        return $chargeType;
    }
}
