<?php

declare(strict_types=1);

namespace App\Marketplace\Entity;

use App\Marketplace\Repository\MappingErrorRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

/**
 * Лог неизвестных затрат маркетплейса.
 *
 * Создаётся в OzonCostsRawProcessor когда service_name не найден
 * в OzonServiceCategoryMap. Используется для мониторинга в админке.
 */
#[ORM\Entity(repositoryClass: MappingErrorRepository::class)]
#[ORM\Table(name: 'marketplace_mapping_errors')]
#[ORM\UniqueConstraint(name: 'uniq_mapping_error', columns: ['company_id', 'marketplace', 'year', 'month', 'service_name'])]
#[ORM\Index(columns: ['company_id'], name: 'idx_mapping_errors_company')]
class MappingError
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'string', length: 50)]
    private string $marketplace;

    #[ORM\Column(type: 'smallint')]
    private int $year;

    #[ORM\Column(type: 'smallint')]
    private int $month;

    #[ORM\Column(type: 'text')]
    private string $serviceName;

    #[ORM\Column(type: 'text')]
    private string $operationType;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2)]
    private string $totalAmount;

    #[ORM\Column(type: 'integer')]
    private int $rowsCount;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $sampleRawJson;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $detectedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    public function __construct(
        string $id,
        string $companyId,
        string $marketplace,
        int $year,
        int $month,
        string $serviceName,
        string $operationType,
        float $totalAmount,
        int $rowsCount = 1,
        ?array $sampleRawJson = null,
    ) {
        Assert::uuid($id);
        $this->id            = $id;
        $this->companyId     = $companyId;
        $this->marketplace   = $marketplace;
        $this->year          = $year;
        $this->month         = $month;
        $this->serviceName   = $serviceName;
        $this->operationType = $operationType;
        $this->totalAmount   = (string) $totalAmount;
        $this->rowsCount     = $rowsCount;
        $this->sampleRawJson = $sampleRawJson;
        $this->detectedAt    = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getCompanyId(): string { return $this->companyId; }
    public function getMarketplace(): string { return $this->marketplace; }
    public function getYear(): int { return $this->year; }
    public function getMonth(): int { return $this->month; }
    public function getServiceName(): string { return $this->serviceName; }
    public function getOperationType(): string { return $this->operationType; }
    public function getTotalAmount(): float { return (float) $this->totalAmount; }
    public function getRowsCount(): int { return $this->rowsCount; }
    public function getSampleRawJson(): ?array { return $this->sampleRawJson; }
    public function getDetectedAt(): \DateTimeImmutable { return $this->detectedAt; }
    public function getResolvedAt(): ?\DateTimeImmutable { return $this->resolvedAt; }
    public function isResolved(): bool { return $this->resolvedAt !== null; }

    public function incrementAmount(float $amount): void
    {
        $this->totalAmount = (string) (round((float) $this->totalAmount + $amount, 2));
        $this->rowsCount++;
        $this->detectedAt = new \DateTimeImmutable();
    }

    public function resolve(): void
    {
        $this->resolvedAt = new \DateTimeImmutable();
    }
}
