<?php

declare(strict_types=1);

namespace App\Marketplace\Exception;

final class MarketplaceRateLimitException extends MarketplaceApiException
{
    public function __construct(
        string $message,
        int $statusCode,
        private readonly ?string $retryAfter,
        ?string $responseExcerpt,
        private readonly string $dateFrom,
        private readonly string $dateTo,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $responseExcerpt, $previous);
    }

    public function getRetryAfter(): ?string
    {
        return $this->retryAfter;
    }

    public function getDateFrom(): string
    {
        return $this->dateFrom;
    }

    public function getDateTo(): string
    {
        return $this->dateTo;
    }
}
