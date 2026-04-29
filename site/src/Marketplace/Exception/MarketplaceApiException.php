<?php

declare(strict_types=1);

namespace App\Marketplace\Exception;

class MarketplaceApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly ?string $responseExcerpt = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseExcerpt(): ?string
    {
        return $this->responseExcerpt;
    }
}
