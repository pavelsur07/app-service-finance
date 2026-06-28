<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Sentry;

use App\Shared\Infrastructure\Sentry\EventScrubber;
use App\Shared\Infrastructure\Sentry\SentryBeforeSend;
use App\Shared\Infrastructure\Sentry\SentryRateLimiter;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Symfony\Component\Clock\MockClock;

final class SentryBeforeSendTest extends TestCase
{
    public function testScrubsPassingEvent(): void
    {
        $beforeSend = $this->beforeSend(limit: 5);

        $event = Event::createEvent();
        $event->setExtra(['password' => 'secret', 'safe' => 'ok']);

        $result = $beforeSend($event);

        self::assertNotNull($result);
        self::assertSame('[Filtered]', $result->getExtra()['password']);
        self::assertSame('ok', $result->getExtra()['safe']);
    }

    public function testDropsThrottledEventBeforeScrubbing(): void
    {
        $beforeSend = $this->beforeSend(limit: 1);

        self::assertNotNull($beforeSend($this->exceptionEvent('boom')), 'first passes');
        self::assertNull($beforeSend($this->exceptionEvent('boom')), 'second is throttled to null');
    }

    private function beforeSend(int $limit): SentryBeforeSend
    {
        return new SentryBeforeSend(
            new SentryRateLimiter(new MockClock('2024-01-01T00:00:00+00:00'), limit: $limit, windowSeconds: 60),
            new EventScrubber(),
        );
    }

    private function exceptionEvent(string $message): Event
    {
        $event = Event::createEvent();
        $event->setExceptions([new ExceptionDataBag(new \RuntimeException($message))]);

        return $event;
    }
}
