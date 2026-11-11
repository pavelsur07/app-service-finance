<?php

declare(strict_types=1);

namespace App\Company\Api\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class AccessibleCompaniesRequest
{
    public function __construct(#[Assert\Positive] public int $page = 1, #[Assert\Range(min: 1, max: 200)] public int $perPage = 50)
    {
    }
}
