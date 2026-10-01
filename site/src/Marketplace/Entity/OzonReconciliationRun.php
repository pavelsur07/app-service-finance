<?php

declare(strict_types=1);

namespace App\Marketplace\Entity;

use App\Marketplace\Enum\OzonReconciliationStatus;
use App\Marketplace\Repository\OzonReconciliationRunRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

/**
 * Актуальный снимок сверки с Ozon за период компании. Один на (компания, период):
 * повторный прогон обновляет снимок, строки (`OzonReconciliationLine`) пересоздаются.
 * Суммы — целые минорные единицы валюты снимка; арифметика ведётся через `Money`.
 */
#[ORM\Entity(repositoryClass: OzonReconciliationRunRepository::class)]
#[ORM\Table(name: 'marketplace_ozon_reconciliation_runs')]
#[ORM\UniqueConstraint(name: 'uniq_mozrr_company_period', columns: ['company_id', 'period_from', 'period_to'])]
#[ORM\Index(name: 'idx_mozrr_company_period_from', columns: ['company_id', 'period_from'])]
class OzonReconciliationRun
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $periodFrom;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $periodTo;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;

    /** Сколько дней периода должно быть в сыром by-day (до вчера, но не раньше старта by-day). */
    #[ORM\Column(type: 'integer')]
    private int $rawDaysExpected = 0;

    /** Сколько из них реально загружено документом. */
    #[ORM\Column(type: 'integer')]
    private int $rawDaysPresent = 0;

    /** Есть ли за период загруженная «Реализация». */
    #[ORM\Column(type: 'boolean')]
    private bool $realizationPresent = false;

    /** Затраты периода вне сверки: их документ — не by-day (легаси v3). Информационно. */
    #[ORM\Column(type: 'bigint')]
    /** bigint гидратируется строкой; наружу отдаётся int. */
    private string $outsideRawCostsMinor = '0';

    #[ORM\Column(type: 'string', length: 32, enumType: OzonReconciliationStatus::class)]
    private OzonReconciliationStatus $overallStatus = OzonReconciliationStatus::NO_DATA;

    #[ORM\Column(type: 'integer')]
    private int $mismatchCount = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $checkedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $id, string $companyId, \DateTimeImmutable $periodFrom, \DateTimeImmutable $periodTo, string $currency = 'RUB')
    {
        Assert::uuid($id);
        Assert::uuid($companyId);
        Assert::regex($currency, '/^[A-Z]{3}$/');
        Assert::lessThanEq($periodFrom, $periodTo);

        $this->id = $id;
        $this->companyId = $companyId;
        $this->periodFrom = $periodFrom;
        $this->periodTo = $periodTo;
        $this->currency = $currency;
        $this->checkedAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function recordResult(
        int $rawDaysExpected,
        int $rawDaysPresent,
        bool $realizationPresent,
        int $outsideRawCostsMinor,
        OzonReconciliationStatus $overallStatus,
        int $mismatchCount,
        \DateTimeImmutable $checkedAt,
    ): void {
        Assert::greaterThanEq($rawDaysExpected, 0);
        Assert::range($rawDaysPresent, 0, max($rawDaysExpected, $rawDaysPresent));
        Assert::greaterThanEq($mismatchCount, 0);

        $this->rawDaysExpected = $rawDaysExpected;
        $this->rawDaysPresent = $rawDaysPresent;
        $this->realizationPresent = $realizationPresent;
        $this->outsideRawCostsMinor = (string) $outsideRawCostsMinor;
        $this->overallStatus = $overallStatus;
        $this->mismatchCount = $mismatchCount;
        $this->checkedAt = $checkedAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getPeriodFrom(): \DateTimeImmutable
    {
        return $this->periodFrom;
    }

    public function getPeriodTo(): \DateTimeImmutable
    {
        return $this->periodTo;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getRawDaysExpected(): int
    {
        return $this->rawDaysExpected;
    }

    public function getRawDaysPresent(): int
    {
        return $this->rawDaysPresent;
    }

    public function hasRealization(): bool
    {
        return $this->realizationPresent;
    }

    public function getOutsideRawCostsMinor(): int
    {
        return (int) $this->outsideRawCostsMinor;
    }

    public function getOverallStatus(): OzonReconciliationStatus
    {
        return $this->overallStatus;
    }

    public function getMismatchCount(): int
    {
        return $this->mismatchCount;
    }

    public function getCheckedAt(): \DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
