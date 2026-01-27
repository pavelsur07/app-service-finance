<?php

namespace App\Company\Entity;

use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: 'company_invites')]
class CompanyInvite
{
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_ACCEPTED = 'ACCEPTED';
    public const STATUS_REVOKED = 'REVOKED';
    public const STATUS_EXPIRED = 'EXPIRED';

    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Company $company;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 32)]
    private string $role;

    #[ORM\Column(length: 255, unique: true)]
    private string $tokenHash;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $createdBy;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $acceptedByUser = null;

    public function __construct(
        string $id,
        Company $company,
        User $createdBy,
        string $email,
        string $role,
        string $tokenHash,
        \DateTimeImmutable $expiresAt,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        Assert::uuid($id);
        Assert::email($email);
        $this->id = $id;
        $this->company = $company;
        $this->createdBy = $createdBy;
        $this->email = mb_strtolower($email);
        $this->role = $role;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getAcceptedByUser(): ?User
    {
        return $this->acceptedByUser;
    }

    public function getStatus(?\DateTimeImmutable $now = null): string
    {
        if ($this->acceptedAt !== null) {
            return self::STATUS_ACCEPTED;
        }

        if ($this->revokedAt !== null) {
            return self::STATUS_REVOKED;
        }

        $now = $now ?? new \DateTimeImmutable();

        if ($this->expiresAt <= $now) {
            return self::STATUS_EXPIRED;
        }

        return self::STATUS_PENDING;
    }

    public function accept(User $user, ?\DateTimeImmutable $at = null): void
    {
        $this->acceptedAt = $at ?? new \DateTimeImmutable();
        $this->acceptedByUser = $user;
    }

    public function revoke(?\DateTimeImmutable $at = null): void
    {
        $this->revokedAt = $at ?? new \DateTimeImmutable();
    }

    public function renewToken(string $tokenHash, \DateTimeImmutable $expiresAt): void
    {
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
    }

    public function isPending(?\DateTimeImmutable $now = null): bool
    {
        return $this->getStatus($now) === self::STATUS_PENDING;
    }
}
