<?php

declare(strict_types=1);

namespace App\Company\Api\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(required: ['targetCompanyId'])]
final readonly class SelectActiveCompanyRequest
{
    public function __construct(#[Assert\NotBlank] #[Assert\Uuid] public string $targetCompanyId)
    {
    }
}
