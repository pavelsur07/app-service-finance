<?php

declare(strict_types=1);

namespace App\Tests\Builders\Company;

use App\Company\Entity\Company;
use App\Company\Entity\User;

final class CompanyBuilder
{
    public const DEFAULT_COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    public const DEFAULT_COMPANY_NAME = 'Company 1';

    private string $id;
    private string $name;
    private User $owner;

    private function __construct()
    {
        $this->id = self::DEFAULT_COMPANY_ID;
        $this->name = self::DEFAULT_COMPANY_NAME;
        $this->owner = UserBuilder::aUser()->build();
    }

    public static function aCompany(): self
    {
        return new self();
    }

    public function withId(string $id): self
    {
        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    public function withIndex(int $index): self
    {
        $clone = clone $this;
        $clone->name = sprintf('Company %d', $index);

        // Детерминированный UUID на основе индекса (уникален в рамках теста)
        $clone->id = sprintf(
            '11111111-1111-1111-1111-%012d',
            $index
        );

        return $clone;
    }

    public function withName(string $name): self
    {
        $clone = clone $this;
        $clone->name = $name;

        return $clone;
    }

    public function withOwner(User $user): self
    {
        $clone = clone $this;
        $clone->owner = $user;

        return $clone;
    }

    public function build(): Company
    {
        $company = new Company($this->id, $this->owner);
        $company->setName($this->name);

        return $company;
    }
}
