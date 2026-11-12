<?php

declare(strict_types=1);

namespace App\Shared\Api\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(required: ['email', 'password'])]
final readonly class SpaLoginRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 180)]
        public string $email = '',
        #[Assert\NotBlank]
        #[Assert\Length(max: 4096)]
        public string $password = '',
    ) {
    }
}
