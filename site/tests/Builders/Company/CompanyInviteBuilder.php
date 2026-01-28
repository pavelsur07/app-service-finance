<?php

declare(strict_types=1);

namespace App\Tests\Builders\Company;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyInvite;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\User;
use Webmozart\Assert\Assert;

final class CompanyInviteBuilder
{
    public const DEFAULT_INVITE_ID = '55555555-5555-5555-5555-555555555555';
    public const DEFAULT_EMAIL = 'operator@example.test';
    public const DEFAULT_ROLE = CompanyMember::ROLE_OPERATOR;
    public const DEFAULT_TOKEN_HASH = 'token-hash';
    public const DEFAULT_EXPIRES_AT = '2026-01-01 00:00:00+00:00';
    public const DEFAULT_CREATED_AT = '2024-01-01 00:00:00+00:00';

    private string $id;
    private Company $company;
    private User $createdBy;
    private string $email;
    private string $role;
    private string $tokenHash;
    private \DateTimeImmutable $expiresAt;
    private \DateTimeImmutable $createdAt;
    private ?\DateTimeImmutable $acceptedAt;
    private ?\DateTimeImmutable $revokedAt;
    private ?User $acceptedByUser;

    private function __construct()
    {
        $this->id = self::DEFAULT_INVITE_ID;
        $this->company = CompanyBuilder::aCompany()->build();
        $this->createdBy = UserBuilder::aUser()->build();
        $this->email = self::DEFAULT_EMAIL;
        $this->role = self::DEFAULT_ROLE;
        $this->tokenHash = self::DEFAULT_TOKEN_HASH;
        $this->expiresAt = new \DateTimeImmutable(self::DEFAULT_EXPIRES_AT);
        $this->createdAt = new \DateTimeImmutable(self::DEFAULT_CREATED_AT);
        $this->acceptedAt = null;
        $this->revokedAt = null;
        $this->acceptedByUser = null;
    }

    public static function anInvite(): self
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

    public function withCreatedBy(User $user): self
    {
        $clone = clone $this;
        $clone->createdBy = $user;

        return $clone;
    }

    public function withEmail(string $email): self
    {
        $clone = clone $this;
        $clone->email = $email;

        return $clone;
    }

    public function withRole(string $role): self
    {
        $clone = clone $this;
        $clone->role = $role;

        return $clone;
    }

    public function withTokenHash(string $tokenHash): self
    {
        $clone = clone $this;
        $clone->tokenHash = $tokenHash;

        return $clone;
    }

    public function withExpiresAt(\DateTimeImmutable $expiresAt): self
    {
        $clone = clone $this;
        $clone->expiresAt = $expiresAt;

        return $clone;
    }

    public function withCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $clone = clone $this;
        $clone->createdAt = $createdAt;

        return $clone;
    }

    public function withPending(): self
    {
        $clone = clone $this;
        $clone->acceptedAt = null;
        $clone->revokedAt = null;
        $clone->acceptedByUser = null;

        return $clone;
    }

    public function withAcceptedAt(?\DateTimeImmutable $acceptedAt = null, ?User $acceptedBy = null): self
    {
        $clone = clone $this;
        $clone->acceptedAt = $acceptedAt ?? new \DateTimeImmutable();
        $clone->acceptedByUser = $acceptedBy ?? $clone->createdBy;
        $clone->revokedAt = null;

        return $clone;
    }

    public function withRevokedAt(?\DateTimeImmutable $revokedAt = null): self
    {
        $clone = clone $this;
        $clone->revokedAt = $revokedAt ?? new \DateTimeImmutable();
        $clone->acceptedAt = null;
        $clone->acceptedByUser = null;

        return $clone;
    }

    public function build(): CompanyInvite
    {
        $invite = new CompanyInvite(
            $this->id,
            $this->company,
            $this->createdBy,
            $this->email,
            $this->role,
            $this->tokenHash,
            $this->expiresAt,
            $this->createdAt,
        );

        if ($this->acceptedAt !== null) {
            Assert::notNull($this->acceptedByUser, 'An accepted invite must have a user who accepted it.');
            $invite->accept($this->acceptedByUser, $this->acceptedAt);
        }

        if ($this->revokedAt !== null) {
            $invite->revoke($this->revokedAt);
        }

        return $invite;
    }
}
