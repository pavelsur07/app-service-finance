<?php

namespace App\Company\Entity;

use App\Company\Repository\CompanyRoleRepository;
use App\Company\Security\AccessLevel;
use App\Company\Security\Module;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: CompanyRoleRepository::class)]
#[ORM\Table(name: 'company_role')]
class CompanyRole
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    /**
     * null — системный шаблон, доступный всем компаниям.
     */
    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Company $company = null;

    #[ORM\Column(length: 128)]
    private string $name;

    /**
     * Карта {moduleValue: levelValue}, напр. {"finance": "write", "catalog": "read"}.
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: 'json')]
    private array $permissions = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, string> $permissions
     */
    public function __construct(
        string $id,
        string $name,
        array $permissions,
        ?Company $company = null,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        Assert::uuid($id);
        $this->id = $id;
        $this->name = $name;
        $this->company = $company;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
        $this->setPermissions($permissions);
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, string>
     */
    public function getPermissions(): array
    {
        return $this->permissions;
    }

    /**
     * @param array<string, string> $permissions
     */
    public function setPermissions(array $permissions): void
    {
        foreach ($permissions as $module => $level) {
            if (null === Module::tryFrom((string) $module)) {
                throw new \InvalidArgumentException(sprintf('Unknown module "%s" in role permissions.', $module));
            }
            if (null === AccessLevel::tryFrom((string) $level)) {
                throw new \InvalidArgumentException(sprintf('Unknown access level "%s" in role permissions.', $level));
            }
        }

        $this->permissions = $permissions;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
