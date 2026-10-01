<?php

declare(strict_types=1);

namespace App\Marketplace\Entity;

use App\Marketplace\Enum\OzonReconciliationBlock;
use App\Marketplace\Enum\OzonReconciliationCheck;
use App\Marketplace\Enum\OzonReconciliationStatus;
use App\Marketplace\Repository\OzonReconciliationLineRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

/**
 * Строка сверки: проверка × блок × категория (пустой код — итог блока).
 * Суммы — минорные единицы валюты снимка; `null` — у стороны нет данных.
 * Разница «цель − источник» фиксируется при создании и не меняется.
 */
#[ORM\Entity(repositoryClass: OzonReconciliationLineRepository::class)]
#[ORM\Table(name: 'marketplace_ozon_reconciliation_lines')]
#[ORM\UniqueConstraint(name: 'uniq_mozrl_run_check_block_category', columns: ['run_id', 'check_type', 'block', 'category_code'])]
#[ORM\Index(name: 'idx_mozrl_company_run', columns: ['company_id', 'run_id'])]
class OzonReconciliationLine
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'guid')]
    private string $runId;

    #[ORM\Column(name: 'check_type', type: 'string', length: 32, enumType: OzonReconciliationCheck::class)]
    private OzonReconciliationCheck $checkType;

    #[ORM\Column(type: 'string', length: 32, enumType: OzonReconciliationBlock::class)]
    private OzonReconciliationBlock $block;

    #[ORM\Column(type: 'string', length: 64, options: ['default' => ''])]
    private string $categoryCode;

    #[ORM\Column(type: 'bigint', nullable: true)]
    /** bigint гидратируется строкой; наружу отдаётся int. */
    private ?string $sourceMinor;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?string $targetMinor;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?string $deltaMinor;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $sourceCount;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $targetCount;

    #[ORM\Column(type: 'string', length: 32, enumType: OzonReconciliationStatus::class)]
    private OzonReconciliationStatus $status;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $note;

    public function __construct(
        string $id,
        string $companyId,
        string $runId,
        OzonReconciliationCheck $check,
        OzonReconciliationBlock $block,
        string $categoryCode,
        ?int $sourceMinor,
        ?int $targetMinor,
        OzonReconciliationStatus $status,
        ?int $sourceCount = null,
        ?int $targetCount = null,
        ?string $note = null,
    ) {
        Assert::uuid($id);
        Assert::uuid($companyId);
        Assert::uuid($runId);
        Assert::maxLength($categoryCode, 64);
        Assert::nullOrMaxLength($note, 255);

        $this->id = $id;
        $this->companyId = $companyId;
        $this->runId = $runId;
        $this->checkType = $check;
        $this->block = $block;
        $this->categoryCode = $categoryCode;
        $this->sourceMinor = null === $sourceMinor ? null : (string) $sourceMinor;
        $this->targetMinor = null === $targetMinor ? null : (string) $targetMinor;
        $this->deltaMinor = null === $sourceMinor || null === $targetMinor ? null : (string) ($targetMinor - $sourceMinor);
        $this->status = $status;
        $this->sourceCount = $sourceCount;
        $this->targetCount = $targetCount;
        $this->note = $note;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getRunId(): string
    {
        return $this->runId;
    }

    public function getCheck(): OzonReconciliationCheck
    {
        return $this->checkType;
    }

    public function getBlock(): OzonReconciliationBlock
    {
        return $this->block;
    }

    public function getCategoryCode(): string
    {
        return $this->categoryCode;
    }

    public function getSourceMinor(): ?int
    {
        return null === $this->sourceMinor ? null : (int) $this->sourceMinor;
    }

    public function getTargetMinor(): ?int
    {
        return null === $this->targetMinor ? null : (int) $this->targetMinor;
    }

    public function getDeltaMinor(): ?int
    {
        return null === $this->deltaMinor ? null : (int) $this->deltaMinor;
    }

    public function getSourceCount(): ?int
    {
        return $this->sourceCount;
    }

    public function getTargetCount(): ?int
    {
        return $this->targetCount;
    }

    public function getStatus(): OzonReconciliationStatus
    {
        return $this->status;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }
}
