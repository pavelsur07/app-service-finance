<?php

declare(strict_types=1);

namespace App\Tests\Builders\Finance;

use App\Company\Entity\Company;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLFlow;
use App\Tests\Builders\Company\CompanyBuilder;
use Ramsey\Uuid\Uuid;

final class PLCategoryBuilder
{
    private string $id;
    private Company $company;
    private string $name;
    private PLFlow $flow;

    private function __construct()
    {
        $this->id = Uuid::uuid4()->toString();
        $this->company = CompanyBuilder::aCompany()->build();
        $this->name = 'Test PL Category';
        $this->flow = PLFlow::EXPENSE;
    }

    public static function aPLCategory(): self
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

    public function withName(string $name): self
    {
        $clone = clone $this;
        $clone->name = $name;

        return $clone;
    }

    public function withFlow(PLFlow $flow): self
    {
        $clone = clone $this;
        $clone->flow = $flow;

        return $clone;
    }

    public function build(): PLCategory
    {
        $category = new PLCategory($this->id, $this->company);
        $category->setName($this->name);
        $category->setFlow($this->flow);

        return $category;
    }
}
