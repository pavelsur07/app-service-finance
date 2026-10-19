<?php

declare(strict_types=1);

namespace App\Admin\Application\DTO;

use Ramsey\Uuid\Uuid;

/**
 * GET-фильтры списка /admin/users, провалидированные whitelist'ом:
 * `company` — UUID кабинета или `none`, `owners` — `1`; прочие значения игнорируются.
 */
final readonly class UserListFilter
{
    private function __construct(
        public ?string $companyId,
        public bool $withoutCompany,
        public bool $ownersOnly,
    ) {
    }

    public static function fromQuery(mixed $company, mixed $owners): self
    {
        $companyId = null;
        $withoutCompany = false;
        if (is_string($company)) {
            if ('none' === $company) {
                $withoutCompany = true;
            } elseif (Uuid::isValid($company)) {
                $companyId = $company;
            }
        }

        return new self($companyId, $withoutCompany, '1' === $owners);
    }

    public function isActive(): bool
    {
        return null !== $this->companyId || $this->withoutCompany || $this->ownersOnly;
    }
}
