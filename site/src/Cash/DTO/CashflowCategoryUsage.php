<?php

declare(strict_types=1);

namespace App\Cash\DTO;

/**
 * Где используется статья ДДС: каждая такая ссылка запрещает её удалить (FK без ON DELETE).
 */
final readonly class CashflowCategoryUsage
{
    public function __construct(
        public int $transactions,
        public int $deletedTransactions,
        public int $paymentPlans,
        public int $autoRules,
    ) {
    }

    public function isUsed(): bool
    {
        return $this->transactions + $this->deletedTransactions + $this->paymentPlans + $this->autoRules > 0;
    }

    /**
     * «По статье 3 операции ДДС, 1 платёж в платёжном календаре.».
     */
    public function summary(): string
    {
        $parts = [];
        if ($this->transactions > 0) {
            $parts[] = self::count($this->transactions, 'операция', 'операции', 'операций').' ДДС';
        }
        if ($this->deletedTransactions > 0) {
            $parts[] = self::count($this->deletedTransactions, 'удалённая операция', 'удалённые операции', 'удалённых операций').' ДДС';
        }
        if ($this->paymentPlans > 0) {
            $parts[] = self::count($this->paymentPlans, 'платёж', 'платежа', 'платежей').' в платёжном календаре';
        }
        if ($this->autoRules > 0) {
            $parts[] = self::count($this->autoRules, 'правило', 'правила', 'правил').' автокатегоризации';
        }

        return 'По статье '.implode(', ', $parts).'.';
    }

    private static function count(int $n, string $one, string $few, string $many): string
    {
        $mod100 = $n % 100;
        $mod10 = $n % 10;
        $form = match (true) {
            $mod100 >= 11 && $mod100 <= 14 => $many,
            1 === $mod10 => $one,
            $mod10 >= 2 && $mod10 <= 4 => $few,
            default => $many,
        };

        return $n.' '.$form;
    }
}
