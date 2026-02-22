<?php

declare(strict_types=1);

namespace App\Catalog\DTO;

use App\Catalog\Enum\ProductStatus;
use Symfony\Component\HttpFoundation\Request;

final class ProductListFilter
{
    public function __construct(
        public readonly ?string $companyId = null,
        public readonly ?string $search = null,
        public readonly ?ProductStatus $status = null,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $search = trim((string) $request->query->get('q', ''));
        $status = ProductStatus::tryFrom(trim((string) $request->query->get('status', '')));

        return new self(
            search: '' !== $search ? $search : null,
            status: $status,
        );
    }

    public function withCompanyId(string $companyId): self
    {
        return new self(
            companyId: $companyId,
            search: $this->search,
            status: $this->status,
        );
    }
}
