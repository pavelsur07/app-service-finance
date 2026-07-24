<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Exception;

final class WildberriesAdRateLimitException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $retryAfterSeconds,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
