<?php

declare(strict_types=1);

namespace App\Balance\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: 'balance_access_grants')]
#[ORM\UniqueConstraint(name: 'uniq_balance_grant_user', columns: ['company_id', 'user_id'])]
class BalanceAccessGrant
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\Column(type: Types::GUID)]
    private string $userId;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $canPrepare = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $canPost = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $canManagePeriods = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $canReopenPeriods = false;

    public function __construct(string $companyId, string $userId, bool $canPrepare = false, bool $canPost = false, bool $canManagePeriods = false, bool $canReopenPeriods = false)
    {
        Assert::uuid($companyId);
        $this->id = Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        Assert::uuid($userId);
        $this->userId = $userId;
        $this->canPrepare = $canPrepare;
        $this->canPost = $canPost;
        $this->canManagePeriods = $canManagePeriods;
        $this->canReopenPeriods = $canReopenPeriods;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function canPrepare(): bool
    {
        return $this->canPrepare;
    }

    public function canPost(): bool
    {
        return $this->canPost;
    }

    public function canManagePeriods(): bool
    {
        return $this->canManagePeriods;
    }

    public function canReopenPeriods(): bool
    {
        return $this->canReopenPeriods;
    }
}
