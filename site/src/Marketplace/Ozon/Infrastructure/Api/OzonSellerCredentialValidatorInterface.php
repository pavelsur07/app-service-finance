<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Infrastructure\Api;

interface OzonSellerCredentialValidatorInterface
{
    public function validate(?string $clientId, string $apiKey): OzonCredentialValidationResult;
}
