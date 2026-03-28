<?php

declare(strict_types=1);

namespace App\Finance\DTO;

use App\Company\Entity\Company;

class DocumentListDTO
{
    public function __construct(
        public readonly Company $company,
        public readonly int $page = 1,
        public readonly int $limit = 20,
    ) {
    }
}
