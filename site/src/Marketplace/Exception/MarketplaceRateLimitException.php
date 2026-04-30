<?php

declare(strict_types=1);

namespace App\Marketplace\Exception;

final class MarketplaceRateLimitException extends MarketplaceApiException
{
    public function __construct(
        int $statusCode,
        string $responseExcerpt,
        string $dateFrom,
        string $dateTo,
        private readonly ?int $retryAfter = null,
    ) {
        parent::__construct('Marketplace API rate limit exceeded.', $statusCode, $responseExcerpt, $dateFrom, $dateTo);
    }

    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
