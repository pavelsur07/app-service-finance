<?php

declare(strict_types=1);

namespace App\Cash\Entity\Transaction;

use App\Cash\Enum\Transaction\IntragroupDecisionType;
use App\Cash\Repository\Transaction\CashIntragroupDecisionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: CashIntragroupDecisionRepository::class)]
#[ORM\Table(name: 'cash_intragroup_decision')]
#[ORM\Index(name: 'idx_cash_intragroup_decision_company', columns: ['company_id'])]
#[ORM\UniqueConstraint(name: 'uniq_cash_intragroup_decision_legs', columns: ['company_id', 'type', 'outflow_transaction_id', 'inflow_transaction_id'])]
#[ORM\UniqueConstraint(name: 'uniq_cash_intragroup_pair_out', columns: ['company_id', 'outflow_transaction_id'], options: ['where' => "((type)::text = 'PAIR'::text)"])]
#[ORM\UniqueConstraint(name: 'uniq_cash_intragroup_pair_in', columns: ['company_id', 'inflow_transaction_id'], options: ['where' => "((type)::text = 'PAIR'::text)"])]
class CashIntragroupDecision
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::GUID)]
        private string $id,
        #[ORM\Column(type: Types::GUID)]
        private string $companyId,
        #[ORM\Column(length: 6, enumType: IntragroupDecisionType::class)]
        private IntragroupDecisionType $type,
        #[ORM\Column(type: Types::GUID)]
        private string $outflowTransactionId,
        #[ORM\Column(type: Types::GUID)]
        private string $inflowTransactionId,
        #[ORM\Column(type: Types::GUID)]
        private string $createdBy,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        Assert::true(UuidV7::isValid($id), 'Идентификатор решения должен быть UUIDv7.');
        Assert::uuid($companyId);
        Assert::uuid($outflowTransactionId);
        Assert::uuid($inflowTransactionId);
        Assert::uuid($createdBy);
        Assert::notEq(strtolower($outflowTransactionId), strtolower($inflowTransactionId), 'Для сверки нужны две разные операции.');
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getType(): IntragroupDecisionType
    {
        return $this->type;
    }

    public function getOutflowTransactionId(): string
    {
        return $this->outflowTransactionId;
    }

    public function getInflowTransactionId(): string
    {
        return $this->inflowTransactionId;
    }

    public function getCreatedBy(): string
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
