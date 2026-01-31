<?php

declare(strict_types=1);

namespace App\Billing\Entity;

use App\Company\Entity\Company;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Billing\Repository\UsageCounterRepository::class)]
#[ORM\Table(name: 'billing_usage_counter')]
#[ORM\UniqueConstraint(name: 'uniq_billing_usage_counter_company_period_metric', columns: ['company_id', 'period_key', 'metric'])]
final class UsageCounter
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(name: 'period_key', type: 'string', length: 7)]
    private string $periodKey;

    #[ORM\Column(type: 'string')]
    private string $metric;

    #[ORM\Column(type: 'bigint')]
    private int $used;

    public function __construct(
        string $id,
        Company $company,
        string $periodKey,
        string $metric,
        int $used,
    ) {
        $this->id = $id;
        $this->company = $company;
        $this->periodKey = $periodKey;
        $this->metric = $metric;
        $this->used = $used;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getPeriodKey(): string
    {
        return $this->periodKey;
    }

    public function getMetric(): string
    {
        return $this->metric;
    }

    public function getUsed(): int
    {
        return $this->used;
    }
}
