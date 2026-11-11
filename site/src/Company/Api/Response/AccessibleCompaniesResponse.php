<?php

declare(strict_types=1);

namespace App\Company\Api\Response;

use App\Shared\OpenApi\Schema\PaginationMeta;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'AccessibleCompaniesResponse', required: ['data', 'meta'], properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object', required: ['id', 'name'], properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'name', type: 'string')])),
    new OA\Property(property: 'meta', ref: new Model(type: PaginationMeta::class)),
])]
final readonly class AccessibleCompaniesResponse
{
    /** @param list<array{id: string, name: string}> $data */
    public function __construct(private array $data, private int $total, private int $page, private int $perPage, private int $pages)
    {
    }

    /** @return array{data: list<array{id: string, name: string}>, meta: array{total: int, page: int, per_page: int, pages: int}} */
    public function toArray(): array
    {
        return ['data' => $this->data, 'meta' => ['total' => $this->total, 'page' => $this->page, 'per_page' => $this->perPage, 'pages' => $this->pages]];
    }
}
