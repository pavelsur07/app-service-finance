<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Sentry;

use Sentry\Event;
use Sentry\EventHint;

/**
 * Композитный before_send-колбэк: сначала троттлинг бёрстов, затем скраб секретов/PII.
 *
 * Sentry допускает только один before_send, поэтому объединяем шаги здесь.
 * Порядок: rate-limit первым (дропнутое событие нет смысла чистить),
 * затем скраб того, что реально уйдёт в GlitchTip.
 */
final readonly class SentryBeforeSend
{
    public function __construct(
        private SentryRateLimiter $rateLimiter,
        private EventScrubber $scrubber,
    ) {
    }

    public function __invoke(Event $event, ?EventHint $hint = null): ?Event
    {
        $limited = ($this->rateLimiter)($event, $hint);
        if (null === $limited) {
            return null;
        }

        return ($this->scrubber)($limited, $hint);
    }
}
