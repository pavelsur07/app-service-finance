<?php

namespace App\Entity;

use App\Repository\ProjectDirectionRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: ProjectDirectionRepository::class)]
#[ORM\Table(name: 'project_directions')]
class ProjectDirection
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Company $company;

    #[ORM\Column(length: 255)]
    private string $name;

    public function __construct(string $id, Company $company, string $name)
    {
        Assert::uuid($id);
        $this->id = $id;
        $this->company = $company;
        $this->name = $name;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function setCompany(Company $company): self
    {
        $this->company = $company;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }
}
