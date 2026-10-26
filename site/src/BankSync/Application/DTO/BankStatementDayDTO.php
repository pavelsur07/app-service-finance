<?php

declare(strict_types=1);

namespace App\BankSync\Application\DTO;

/**
 * Выписка счёта за день: все страницы, разобранные адаптером.
 */
final readonly class BankStatementDayDTO
{
    /**
     * @param list<BankStatementLineDTO> $lines
     * @param int $skippedLines строки, которые адаптер не смог разобрать
     */
    public function __construct(
        public \DateTimeImmutable $date,
        public array $lines,
        public int $skippedLines = 0,
    ) {
    }
}
