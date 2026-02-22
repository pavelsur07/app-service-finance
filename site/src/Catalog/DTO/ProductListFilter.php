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
        public readonly bool $includeArchived = false,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $search = trim((string) $request->query->get('q', ''));
        $status = ProductStatus::tryFrom(trim((string) $request->query->get('status', '')));

        return new self(
            search: '' !== $search ? $search : null,
            status: $status,
            includeArchived: filter_var($request->query->get('includeArchived', false), FILTER_VALIDATE_BOOL),
        );
    }

    public function withCompanyId(string $companyId): self
    {
        return new self(
            companyId: $companyId,
            search: $this->search,
            status: $this->status,
            includeArchived: $this->includeArchived,
        );
    }
}
