<?php

declare(strict_types=1);

namespace App\Balance\Entity;

use App\Balance\Enum\BalanceDirection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: 'balance_operation_lines')]
#[ORM\UniqueConstraint(name: 'uniq_balance_line_account', columns: ['company_id', 'operation_id', 'account_id'])]
#[ORM\Index(name: 'idx_balance_line_account', columns: ['company_id', 'account_id', 'operation_id'])]
class BalanceOperationLine
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\Column(type: Types::GUID)]
    private string $operationId;

    #[ORM\Column(type: Types::GUID)]
    private string $accountId;

    #[ORM\Column(length: 10, enumType: BalanceDirection::class)]
    private BalanceDirection $direction;

    #[ORM\Column(type: Types::BIGINT)]
    private string $amount;

    public function __construct(string $companyId, string $operationId, string $accountId, BalanceDirection $direction, string $amount)
    {
        Assert::uuid($companyId);
        $this->id = Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        Assert::uuid($operationId);
        Assert::uuid($accountId);
        Assert::regex($amount, '/^[1-9][0-9]{0,18}$/D');
        Assert::true(bccomp($amount, '9223372036854775807', 0) <= 0);
        $this->operationId = $operationId;
        $this->accountId = $accountId;
        $this->direction = $direction;
        $this->amount = $amount;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getOperationId(): string
    {
        return $this->operationId;
    }

    public function getAccountId(): string
    {
        return $this->accountId;
    }

    public function getDirection(): BalanceDirection
    {
        return $this->direction;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }
}
