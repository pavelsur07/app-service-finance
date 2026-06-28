<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Sentry;

use App\Shared\Infrastructure\Sentry\SentryRateLimiter;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Symfony\Component\Clock\MockClock;

final class SentryRateLimiterTest extends TestCase
{
    public function testAllowsFirstNThenThrottlesWithinWindow(): void
    {
        $limiter = new SentryRateLimiter(new MockClock('2024-01-01T00:00:00+00:00'), limit: 2, windowSeconds: 60);

        self::assertNotNull($limiter($this->exceptionEvent('boom')), 'first must pass');
        self::assertNotNull($limiter($this->exceptionEvent('boom')), 'second must pass');
        self::assertNull($limiter($this->exceptionEvent('boom')), 'third must be throttled');
    }

    public function testWindowResetsAfterExpiry(): void
    {
        $clock = new MockClock('2024-01-01T00:00:00+00:00');
        $limiter = new SentryRateLimiter($clock, limit: 1, windowSeconds: 60);

        self::assertNotNull($limiter($this->exceptionEvent('boom')));
        self::assertNull($limiter($this->exceptionEvent('boom')), 'throttled in same window');

        $clock->sleep(60);

        self::assertNotNull($limiter($this->exceptionEvent('boom')), 'new window must pass again');
    }

    public function testDifferentSignaturesAreLimitedIndependently(): void
    {
        $limiter = new SentryRateLimiter(new MockClock('2024-01-01T00:00:00+00:00'), limit: 1, windowSeconds: 60);

        self::assertNotNull($limiter($this->exceptionEvent('boom', \RuntimeException::class)));
        self::assertNull($limiter($this->exceptionEvent('boom', \RuntimeException::class)), 'same signature throttled');

        // Другой класс/сообщение — независимый счётчик.
        self::assertNotNull($limiter($this->exceptionEvent('other', \LogicException::class)));
    }

    public function testFallsBackToMessageKeyWhenNoException(): void
    {
        $limiter = new SentryRateLimiter(new MockClock('2024-01-01T00:00:00+00:00'), limit: 1, windowSeconds: 60);

        $first = Event::createEvent();
        $first->setMessage('repeated message');
        $second = Event::createEvent();
        $second->setMessage('repeated message');

        self::assertNotNull($limiter($first));
        self::assertNull($limiter($second), 'identical message must be throttled');
    }

    private function exceptionEvent(string $message, string $class = \RuntimeException::class): Event
    {
        $event = Event::createEvent();
        $event->setExceptions([new ExceptionDataBag(new $class($message))]);

        return $event;
    }
}
