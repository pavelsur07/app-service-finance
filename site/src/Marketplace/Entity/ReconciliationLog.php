<?php

namespace App\Marketplace\Entity;

use App\Marketplace\Repository\ReconciliationLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

/**
 * ReconciliationLog - лог проверок сверки данных
 *
 * Сохраняет результаты каждой проверки:
 * - Проверка количества продаж
 * - Проверка суммы продаж
 * - Проверка возвратов
 * - Проверка расходов
 * - Финальная сверка
 */
#[ORM\Entity(repositoryClass: ReconciliationLogRepository::class)]
#[ORM\Table(name: 'marketplace_reconciliation_log')]
#[ORM\Index(columns: ['processing_batch_id', 'check_type'], name: 'idx_recon_batch_type')]
#[ORM\Index(columns: ['passed'], name: 'idx_recon_passed')]
class ReconciliationLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: ProcessingBatch::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ProcessingBatch $processingBatch;

    /**
     * Тип проверки:
     * - sales_count - количество продаж
     * - sales_amount - сумма продаж
     * - returns_count - количество возвратов
     * - returns_amount - сумма возвратов
     * - costs_check - проверка расходов
     * - final_reconciliation - финальная сверка
     */
    #[ORM\Column(type: 'string', length: 50)]
    private string $checkType;

    /**
     * Прошла ли проверка
     */
    #[ORM\Column(type: 'boolean')]
    private bool $passed;

    /**
     * Детали проверки
     * {
     *   "expected": 1000,
     *   "actual": 998,
     *   "difference": -2,
     *   "expected_amount": 150000.00,
     *   "actual_amount": 149500.00,
     *   "amount_difference": -500.00,
     *   "threshold": 0.01,
     *   "notes": "2 records failed validation"
     * }
     */
    #[ORM\Column(type: 'json')]
    private array $details;

    /**
     * Когда проверка была выполнена
     */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $checkedAt;

    public function __construct(
        string $id,
        ProcessingBatch $processingBatch,
        string $checkType,
        bool $passed,
        array $details
    ) {
        Assert::uuid($id);
        Assert::notEmpty($checkType);

        $this->id = $id;
        $this->processingBatch = $processingBatch;
        $this->checkType = $checkType;
        $this->passed = $passed;
        $this->details = $details;
        $this->checkedAt = new \DateTimeImmutable();
    }

    // === GETTERS ===

    public function getId(): string
    {
        return $this->id;
    }

    public function getProcessingBatch(): ProcessingBatch
    {
        return $this->processingBatch;
    }

    public function getCheckType(): string
    {
        return $this->checkType;
    }

    public function isPassed(): bool
    {
        return $this->passed;
    }

    public function getDetails(): array
    {
        return $this->details;
    }

    public function getCheckedAt(): \DateTimeImmutable
    {
        return $this->checkedAt;
    }

    // === HELPER METHODS ===

    /**
     * Получить значение из деталей
     */
    public function getDetail(string $key, mixed $default = null): mixed
    {
        return $this->details[$key] ?? $default;
    }

    /**
     * Получить человекочитаемое описание проверки
     */
    public function getCheckTypeLabel(): string
    {
        return match ($this->checkType) {
            'sales_count' => 'Количество продаж',
            'sales_amount' => 'Сумма продаж',
            'returns_count' => 'Количество возвратов',
            'returns_amount' => 'Сумма возвратов',
            'costs_check' => 'Проверка расходов',
            'final_reconciliation' => 'Финальная сверка',
            default => $this->checkType,
        };
    }

    /**
     * Получить краткое описание результата
     */
    public function getSummary(): string
    {
        $expected = $this->getDetail('expected', 0);
        $actual = $this->getDetail('actual', 0);
        $diff = $this->getDetail('difference', 0);

        if ($this->passed) {
            return sprintf('✓ Ожидалось: %d, Фактически: %d', $expected, $actual);
        }

        return sprintf('✗ Ожидалось: %d, Фактически: %d (разница: %d)', $expected, $actual, $diff);
    }

    /**
     * Есть ли расхождения в суммах
     */
    public function hasAmountDiscrepancy(): bool
    {
        $expectedAmount = $this->getDetail('expected_amount');
        $actualAmount = $this->getDetail('actual_amount');

        return $expectedAmount !== null
            && $actualAmount !== null
            && abs((float)$expectedAmount - (float)$actualAmount) > 0.01;
    }

    /**
     * Получить описание расхождения в суммах
     */
    public function getAmountDiscrepancySummary(): ?string
    {
        if (!$this->hasAmountDiscrepancy()) {
            return null;
        }

        $expected = $this->getDetail('expected_amount', 0);
        $actual = $this->getDetail('actual_amount', 0);
        $diff = $this->getDetail('amount_difference', 0);

        return sprintf(
            'Ожидалось: %.2f ₽, Фактически: %.2f ₽ (разница: %.2f ₽)',
            (float)$expected,
            (float)$actual,
            (float)$diff
        );
    }
}
