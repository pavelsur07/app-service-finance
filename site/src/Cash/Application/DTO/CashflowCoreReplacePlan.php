<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

/**
 * План замены дерева ДДС компании ядром `core.yaml`.
 */
final readonly class CashflowCoreReplacePlan
{
    /**
     * @param list<string> $removable путь статей без ссылок, которые будут удалены
     * @param list<string> $blocked «путь — где используется» и ожидающие ответа ИИ: каждая запрещает замену
     */
    public function __construct(
        public bool $alreadyApplied,
        public array $removable,
        public array $blocked,
        public int $staleSuggestions,
        public int $coreNodes,
        /** Дерево уже совпадает с ядром: столько статей получат код консолидации. */
        public int $codesToAssign,
    ) {
    }
}
