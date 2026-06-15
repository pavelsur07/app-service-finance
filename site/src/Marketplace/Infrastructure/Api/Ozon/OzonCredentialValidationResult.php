<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Api\Ozon;

final readonly class OzonCredentialValidationResult
{
    public function __construct(
        public OzonCredentialValidationStatus $status,
        public string $message,
        public ?int $statusCode = null,
    ) {
    }

    public static function valid(): self
    {
        return new self(
            OzonCredentialValidationStatus::VALID,
            'Ключ Ozon Seller API успешно проверен.',
        );
    }

    public function isValid(): bool
    {
        return OzonCredentialValidationStatus::VALID === $this->status;
    }
}
