<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan\data\Query;

/**
 * Фикстура: репозиторий вне namespace `*\Repository\*`. Сущность определить
 * нельзя, поэтому принадлежность считается подтверждённой — нарушение есть.
 */
final class LedgerRepository
{
    public function findByPeriod(string $period): array
    {
        return [$period];
    }
}
