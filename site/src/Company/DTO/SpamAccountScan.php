<?php

declare(strict_types=1);

namespace App\Company\DTO;

/**
 * Результат поиска спам-аккаунтов в окне регистрации.
 */
final readonly class SpamAccountScan
{
    /**
     * @param list<SpamAccountCandidate> $candidates
     * @param array<string, int> $rejectedByReason причина (`admin`, `no_company`, `таблица.колонка`) → число пользователей
     */
    public function __construct(
        public int $usersInWindow,
        public array $candidates,
        public array $rejectedByReason,
    ) {
    }

    public function companyCount(): int
    {
        return array_sum(array_map(static fn (SpamAccountCandidate $c): int => \count($c->companyIds), $this->candidates));
    }
}
