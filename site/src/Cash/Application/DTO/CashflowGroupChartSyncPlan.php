<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

/**
 * План (или результат) синхронизации дерева ДДС компании с эталоном группы.
 * source: core — core.yaml, head — дерево головной компании headCompanyId.
 */
final readonly class CashflowGroupChartSyncPlan
{
    /**
     * @param list<string> $bind «путь статьи компании → код»
     * @param list<string> $create «путь в эталоне (код)»
     * @param list<string> $conflicts «код «название»: причина»
     */
    public function __construct(
        public string $source,
        public ?string $headCompanyId,
        public array $bind,
        public array $create,
        public array $conflicts,
        public int $headCodes,
    ) {
    }

    /** Нечего менять: конфликты сами по себе изменений не требуют. */
    public function isEmpty(): bool
    {
        return [] === $this->bind && [] === $this->create && 0 === $this->headCodes;
    }
}
