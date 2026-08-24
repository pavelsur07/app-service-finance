<?php

declare(strict_types=1);

namespace App\Company\Entity;

use App\Company\Repository\CompanyMemberRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: CompanyMemberRepository::class)]
#[ORM\Table(name: 'company_members')]
#[ORM\UniqueConstraint(name: 'uniq_company_members_company_user', columns: ['company_id', 'user_id'])]
class CompanyMember
{
    public const ROLE_OWNER = 'OWNER';
    public const ROLE_OPERATOR = 'OPERATOR';

    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_DISABLED = 'DISABLED';

    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(length: 32)]
    private string $role;

    /**
     * Шаблон модульного доступа. `null` означает отсутствие доступа: legacy-fallback
     * по строковой роли снят в Stage 3, потому что был fail-open — обнуление role_id
     * повышало права вместо их снятия. FK объявлен RESTRICT, чтобы назначенный шаблон
     * нельзя было удалить и обнулить ссылку гонкой.
     */
    #[ORM\ManyToOne(targetEntity: CompanyRole::class)]
    #[ORM\JoinColumn(name: 'role_id', nullable: true, onDelete: 'RESTRICT')]
    private ?CompanyRole $accessRole = null;

    #[ORM\Column(length: 32)]
    private string $status;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $id, Company $company, User $user, string $role, ?\DateTimeImmutable $createdAt = null)
    {
        Assert::uuid($id);
        $this->id = $id;
        $this->company = $company;
        $this->user = $user;
        $this->role = $role;
        $this->status = self::STATUS_ACTIVE;
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

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getAccessRole(): ?CompanyRole
    {
        return $this->accessRole;
    }

    public function setAccessRole(?CompanyRole $accessRole): void
    {
        $this->accessRole = $accessRole;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function disable(): void
    {
        $this->status = self::STATUS_DISABLED;
    }

    public function enable(): void
    {
        $this->status = self::STATUS_ACTIVE;
    }
}
