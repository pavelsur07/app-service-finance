<?php

declare(strict_types=1);

namespace App\Balance\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: 'balance_account_states')]
#[ORM\UniqueConstraint(name: 'uniq_balance_state_account', columns: ['company_id', 'account_id'])]
class BalanceAccountState
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\Column(type: Types::GUID)]
    private string $accountId;

    #[ORM\Column(type: Types::BIGINT)]
    private string $balance = '0';

    #[ORM\Column(type: Types::INTEGER)]
    private int $journalVersion = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $companyId, string $accountId)
    {
        Assert::uuid($companyId);
        $this->id = Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        Assert::uuid($accountId);
        $this->accountId = $accountId;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getAccountId(): string
    {
        return $this->accountId;
    }

    public function getBalance(): string
    {
        return $this->balance;
    }

    public function getJournalVersion(): int
    {
        return $this->journalVersion;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
