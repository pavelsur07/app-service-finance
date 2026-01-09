<?php

namespace App\Marketplace\Ozon\Entity;

use App\Entity\Company;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ozon_sync_cursor')]
#[ORM\UniqueConstraint(name: 'uniq_company_scheme', columns: ['company_id', 'scheme'])]
class OzonSyncCursor
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Company $company;

    #[ORM\Column(length: 3)]
    private string $scheme;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastSince = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastTo = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastRunAt = null;

    public function __construct(string $id, Company $company, string $scheme)
    {
        $this->id = $id;
        $this->company = $company;
        $this->scheme = $scheme;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function setCompany(Company $company): void
    {
        $this->company = $company;
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function setScheme(string $scheme): void
    {
        $this->scheme = $scheme;
    }

    public function getLastSince(): ?\DateTimeImmutable
    {
        return $this->lastSince;
    }

    public function setLastSince(?\DateTimeImmutable $lastSince): void
    {
        $this->lastSince = $lastSince;
    }

    public function getLastTo(): ?\DateTimeImmutable
    {
        return $this->lastTo;
    }

    public function setLastTo(?\DateTimeImmutable $lastTo): void
    {
        $this->lastTo = $lastTo;
    }

    public function getLastRunAt(): ?\DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    public function setLastRunAt(?\DateTimeImmutable $lastRunAt): void
    {
        $this->lastRunAt = $lastRunAt;
    }
}
