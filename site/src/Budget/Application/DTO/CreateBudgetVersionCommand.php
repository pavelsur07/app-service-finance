<?php

declare(strict_types=1);

namespace App\Budget\Application\DTO;

/**
 * `versionId` задаёт вызывающий (скрытый UUIDv7 формы) — ключ повтора. Для копии год берётся из
 * исходного варианта, `year` не используется.
 */
final readonly class CreateBudgetVersionCommand
{
    public function __construct(
        public string $versionId,
        public string $companyId,
        public string $name,
        public ?int $year = null,
        public ?string $copyFromVersionId = null,
    ) {
    }
}
