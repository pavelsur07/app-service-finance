<?php

declare(strict_types=1);

namespace App\Company\DTO;

/**
 * Пользователь, которого можно удалить вместе с его пустыми кабинетами.
 */
final readonly class SpamAccountCandidate
{
    /**
     * @param non-empty-list<string> $companyIds
     */
    public function __construct(
        public string $userId,
        public array $companyIds,
    ) {
    }
}
