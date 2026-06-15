<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Api\Ozon;

interface OzonSellerCredentialValidatorInterface
{
    public function validate(?string $clientId, string $apiKey): OzonCredentialValidationResult;
}
