<?php

declare(strict_types=1);

namespace App\BankSync\Infrastructure\Bank\Alfa;

use App\BankSync\Application\DTO\BankStatementLineDTO;

/**
 * Одна страница выписки Альфы после разбора.
 */
final readonly class AlfaStatementPage
{
    /**
     * @param list<BankStatementLineDTO> $lines
     * @param int $skippedLines строки без идентификатора, даты, суммы или направления
     */
    public function __construct(
        public array $lines,
        public int $skippedLines,
        public bool $hasNextPage,
    ) {
    }
}
