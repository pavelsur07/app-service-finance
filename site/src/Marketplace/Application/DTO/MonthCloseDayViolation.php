<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

/**
 * Один ожидаемый день отчётного периода, который не готов к закрытию месяца.
 */
final readonly class MonthCloseDayViolation
{
    public const STATE_MISSING = 'missing';
    public const STATE_IN_PROGRESS = 'in_progress';
    public const STATE_FAILED = 'failed';

    /**
     * @param self::STATE_* $state
     * @param ?string $status значение FinancialReportSyncStatus; null — строки статуса нет
     */
    public function __construct(
        public string $businessDate,
        public string $state,
        public ?string $status,
    ) {
    }

    public function label(): string
    {
        return sprintf('%s %s', $this->businessDate, $this->status ?? self::STATE_MISSING);
    }

    /** @return array{business_date: string, state: string, status: ?string} */
    public function toArray(): array
    {
        return ['business_date' => $this->businessDate, 'state' => $this->state, 'status' => $this->status];
    }
}
