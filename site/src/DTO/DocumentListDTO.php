<?php

namespace App\DTO;

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
