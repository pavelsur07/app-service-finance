<?php

declare(strict_types=1);

namespace App\Tests\Builders\Company;

use App\Company\Entity\Company;
use App\Company\Enum\CounterpartyType;
use App\Entity\Counterparty;
use Webmozart\Assert\Assert;

final class CounterpartyBuilder
{
    public const DEFAULT_COUNTERPARTY_ID = '33333333-3333-3333-3333-333333333333';
    public const DEFAULT_NAME = 'Counterparty 1';
    public const DEFAULT_INN = '7707083893';
    public const DEFAULT_TYPE = CounterpartyType::LEGAL_ENTITY;
    public const DEFAULT_UPDATED_AT = '2024-01-01 00:00:00+00:00';
    public const DEFAULT_IS_ARCHIVED = false;

    private string $id;
    private Company $company;
    private string $name;
    private ?string $inn;
    private CounterpartyType $type;
    private \DateTimeImmutable $updatedAt;
    private bool $isArchived;

    private function __construct()
    {
        $this->id = self::DEFAULT_COUNTERPARTY_ID;
        $this->company = CompanyBuilder::aCompany()->build();
        $this->name = self::DEFAULT_NAME;
        $this->inn = self::DEFAULT_INN;
        $this->type = self::DEFAULT_TYPE;
        $this->updatedAt = new \DateTimeImmutable(self::DEFAULT_UPDATED_AT);
        $this->isArchived = self::DEFAULT_IS_ARCHIVED;
    }

    public static function aCounterparty(): self
    {
        return new self();
    }

    public function withId(string $id): self
    {
        Assert::uuid($id);

        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    public function withCompany(Company $company): self
    {
        $clone = clone $this;
        $clone->company = $company;

        return $clone;
    }

    public function withIndex(int $index): self
    {
        $clone = clone $this;
        $clone->name = sprintf('Counterparty %d', $index);

        return $clone;
    }

    public function withName(string $name): self
    {
        if ('' === $name) {
            throw new \InvalidArgumentException('Name cannot be empty.');
        }

        if (mb_strlen($name) > 255) {
            throw new \InvalidArgumentException('Name must be 255 characters or fewer.');
        }

        $clone = clone $this;
        $clone->name = $name;

        return $clone;
    }

    public function withInn(?string $inn): self
    {
        if (null !== $inn && 1 !== preg_match('/^\d{10}(\d{2})?$/', $inn)) {
            throw new \InvalidArgumentException('Inn must contain 10 or 12 digits.');
        }

        $clone = clone $this;
        $clone->inn = $inn;

        return $clone;
    }

    public function withType(CounterpartyType $type): self
    {
        $clone = clone $this;
        $clone->type = $type;

        return $clone;
    }

    public function withUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $clone = clone $this;
        $clone->updatedAt = $updatedAt;

        return $clone;
    }

    public function asArchived(): self
    {
        $clone = clone $this;
        $clone->isArchived = true;

        return $clone;
    }

    public function build(): Counterparty
    {
        $counterparty = new Counterparty($this->id, $this->company, $this->name, $this->type);
        $counterparty->setInn($this->inn);
        $counterparty->setUpdatedAt($this->updatedAt);
        $counterparty->setIsArchived($this->isArchived);

        return $counterparty;
    }
}
