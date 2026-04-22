<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Exception;

/**
 * Ozon Performance API returned HTTP 429 — "max 1 active request".
 *
 * Ozon's backend measures this by UUID-creation occupancy, not by
 * concurrent HTTP connections. Retrying within seconds is futile.
 *
 * The handler catches this and reschedules the message via DelayStamp
 * so the worker stays free and the message reappears after Ozon's
 * backend releases the slot (typically 30-60 seconds).
 */
final class OzonRateLimitException extends \RuntimeException
{
    public function __construct(string $message = 'Ozon Performance: HTTP 429, rate-limited', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
