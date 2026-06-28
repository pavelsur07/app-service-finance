<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Sentry;

use App\Shared\Infrastructure\Sentry\SentryMessengerScopeSubscriber;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

final class SentryMessengerScopeSubscriberTest extends TestCase
{
    public function testSetsMessageClassAndCompanyIdTags(): void
    {
        $message = new class {
            public string $companyId = 'comp-123';
        };

        $event = $this->scopeAfterReceiving($message, new Scope());

        self::assertSame('comp-123', $event->getTags()['company_id'] ?? null);
        self::assertSame($message::class, $event->getTags()['messenger.message'] ?? null);
    }

    public function testClearsStaleCompanyIdWhenMessageHasNone(): void
    {
        // Воркер последовательно обрабатывает сообщения; сообщение без companyId
        // не должно унаследовать тег от предыдущего.
        $scope = new Scope();
        $scope->setTag('company_id', 'stale');

        $message = new class {};
        $event = $this->scopeAfterReceiving($message, $scope);

        self::assertArrayNotHasKey('company_id', $event->getTags());
        self::assertSame($message::class, $event->getTags()['messenger.message'] ?? null);
    }

    public function testReadsCompanyIdFromGetter(): void
    {
        $message = new class {
            public function getCompanyId(): string
            {
                return 'from-getter';
            }
        };

        $event = $this->scopeAfterReceiving($message, new Scope());

        self::assertSame('from-getter', $event->getTags()['company_id'] ?? null);
    }

    private function scopeAfterReceiving(object $message, Scope $scope): Event
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())
            ->method('configureScope')
            ->willReturnCallback(static fn (callable $cb) => $cb($scope));

        $subscriber = new SentryMessengerScopeSubscriber($hub);
        $subscriber->onMessageReceived(new WorkerMessageReceivedEvent(new Envelope($message), 'async'));

        $event = Event::createEvent();
        $scope->applyToEvent($event);

        return $event;
    }
}
