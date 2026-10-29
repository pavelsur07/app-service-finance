<?php

declare(strict_types=1);

namespace App\Finance\Application\DTO;

/**
 * Что не даёт удалить статью ОПиУ: вложенные статьи и записи других таблиц, ссылающиеся на неё
 * без ON DELETE. Агрегаты отчёта (`pl_daily_totals`) не в счёт — удаление переносит их в
 * «Без категории».
 */
final readonly class PLCategoryUsage
{
    /**
     * @param list<array{id: string, name: string}> $cashflowCategoryRefs связанные статьи ДДС поимённо
     * @param list<array{id: string, name: string}> $loanRefs кредиты поимённо
     */
    public function __construct(
        public int $children,
        public int $documentOperations,
        public int $deletedDocumentOperations,
        public int $cashflowCategories,
        public int $loans,
        public int $saleMappings,
        public array $cashflowCategoryRefs = [],
        public array $loanRefs = [],
    ) {
    }

    public function isUsed(): bool
    {
        return $this->children + $this->documentOperations + $this->deletedDocumentOperations
            + $this->cashflowCategories + $this->loans + $this->saleMappings > 0;
    }

    /**
     * «2 вложенные статьи, 3 операции документов ОПиУ, 1 связанная статья ДДС».
     */
    public function summary(): string
    {
        $parts = [];
        if ($this->children > 0) {
            $parts[] = self::count($this->children, 'вложенная статья', 'вложенные статьи', 'вложенных статей');
        }
        if ($this->documentOperations > 0) {
            $parts[] = self::count($this->documentOperations, 'операция', 'операции', 'операций').' документов ОПиУ';
        }
        if ($this->deletedDocumentOperations > 0) {
            $parts[] = self::count($this->deletedDocumentOperations, 'операция', 'операции', 'операций').' в удалённых документах ОПиУ';
        }
        if ($this->cashflowCategories > 0) {
            $parts[] = self::count($this->cashflowCategories, 'связанная статья', 'связанные статьи', 'связанных статей').' ДДС';
        }
        if ($this->loans > 0) {
            $parts[] = self::count($this->loans, 'кредит', 'кредита', 'кредитов').' (проценты или комиссия)';
        }
        if ($this->saleMappings > 0) {
            $parts[] = self::count($this->saleMappings, 'правило', 'правила', 'правил').' разнесения продаж маркетплейсов';
        }

        return implode(', ', $parts);
    }

    /**
     * Что сделать, чтобы статью можно было удалить, — по тому, что мешает.
     */
    public function advice(): string
    {
        $steps = [];
        if ($this->children > 0) {
            $steps[] = 'перенесите или удалите вложенные статьи';
        }
        if ($this->documentOperations + $this->deletedDocumentOperations + $this->cashflowCategories + $this->loans + $this->saleMappings > 0) {
            $steps[] = 'переназначьте ссылки на другую статью';
        }

        $text = implode(' и ', $steps);

        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1).', затем удалите эту.';
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
