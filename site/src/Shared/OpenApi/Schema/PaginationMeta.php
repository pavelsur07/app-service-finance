<?php

declare(strict_types=1);

namespace App\Shared\OpenApi\Schema;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PaginationMeta',
    description: 'Метаданные пагинации для list-эндпоинтов',
    required: ['total', 'page', 'per_page', 'pages'],
    properties: [
        new OA\Property(property: 'total', type: 'integer', example: 7308, description: 'Общее количество элементов'),
        new OA\Property(property: 'page', type: 'integer', example: 1, description: 'Текущая страница (1-based)'),
        new OA\Property(property: 'per_page', type: 'integer', example: 20, description: 'Элементов на странице'),
        new OA\Property(property: 'pages', type: 'integer', example: 366, description: 'Общее количество страниц'),
    ]
)]
final class PaginationMeta
{
}
