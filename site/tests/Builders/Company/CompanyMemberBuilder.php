<?php

declare(strict_types=1);

namespace App\Tests\Builders\Company;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\User;
use Webmozart\Assert\Assert;

final class CompanyMemberBuilder
{
    public const DEFAULT_MEMBER_ID = '44444444-4444-4444-4444-444444444444';
    public const DEFAULT_ROLE = CompanyMember::ROLE_OPERATOR;
    public const DEFAULT_STATUS = CompanyMember::STATUS_ACTIVE;
    public const DEFAULT_CREATED_AT = '2024-01-01 00:00:00+00:00';

    private string $id;
    private Company $company;
    private User $user;
    private string $role;
    private string $status;
    private \DateTimeImmutable $createdAt;

    private function __construct()
    {
        $this->id = self::DEFAULT_MEMBER_ID;
        $this->company = CompanyBuilder::aCompany()->build();
        $this->user = UserBuilder::aUser()->build();
        $this->role = self::DEFAULT_ROLE;
        $this->status = self::DEFAULT_STATUS;
        $this->createdAt = new \DateTimeImmutable(self::DEFAULT_CREATED_AT);
    }

    public static function aMember(): self
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

    public function withUser(User $user): self
    {
        $clone = clone $this;
        $clone->user = $user;

        return $clone;
    }

    public function withRole(string $role): self
    {
        $clone = clone $this;
        $clone->role = $role;

        return $clone;
    }

    public function withStatus(string $status): self
    {
        if (!in_array($status, [CompanyMember::STATUS_ACTIVE, CompanyMember::STATUS_DISABLED], true)) {
            throw new \InvalidArgumentException('Unsupported status.');
        }

        $clone = clone $this;
        $clone->status = $status;

        return $clone;
    }

    public function withCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $clone = clone $this;
        $clone->createdAt = $createdAt;

        return $clone;
    }

    public function asDisabled(): self
    {
        $clone = clone $this;
        $clone->status = CompanyMember::STATUS_DISABLED;

        return $clone;
    }

    public function build(): CompanyMember
    {
        $member = new CompanyMember($this->id, $this->company, $this->user, $this->role, $this->createdAt);

        if ($this->status === CompanyMember::STATUS_DISABLED) {
            $member->disable();
        }

        return $member;
    }
}
