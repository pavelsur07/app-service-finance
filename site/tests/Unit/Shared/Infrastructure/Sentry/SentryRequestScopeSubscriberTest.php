<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Sentry;

use App\Shared\Audit\AuditContextProvider;
use App\Shared\Infrastructure\Sentry\SentryRequestScopeSubscriber;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SentryRequestScopeSubscriberTest extends TestCase
{
    public function testSetsUserAndCompanyTagsForMainRequest(): void
    {
        $audit = $this->createMock(AuditContextProvider::class);
        $audit->method('getActorUserId')->willReturn('user-1');
        $audit->method('getCompanyId')->willReturn('comp-1');

        $scope = new Scope();
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())
            ->method('configureScope')
            ->willReturnCallback(static fn (callable $cb) => $cb($scope));

        (new SentryRequestScopeSubscriber($hub, $audit))->onKernelRequest($this->requestEvent(HttpKernelInterface::MAIN_REQUEST));

        $event = Event::createEvent();
        $scope->applyToEvent($event);

        self::assertSame('user-1', $event->getUser()?->getId());
        self::assertSame('comp-1', $event->getTags()['company_id'] ?? null);
    }

    public function testSkipsWhenNoUserOrCompany(): void
    {
        $audit = $this->createMock(AuditContextProvider::class);
        $audit->method('getActorUserId')->willReturn(null);
        $audit->method('getCompanyId')->willReturn(null);

        $scope = new Scope();
        $hub = $this->createMock(HubInterface::class);
        $hub->method('configureScope')->willReturnCallback(static fn (callable $cb) => $cb($scope));

        (new SentryRequestScopeSubscriber($hub, $audit))->onKernelRequest($this->requestEvent(HttpKernelInterface::MAIN_REQUEST));

        $event = Event::createEvent();
        $scope->applyToEvent($event);

        self::assertNull($event->getUser());
        self::assertArrayNotHasKey('company_id', $event->getTags());
    }

    public function testIgnoresSubRequest(): void
    {
        $audit = $this->createMock(AuditContextProvider::class);
        $audit->expects(self::never())->method('getActorUserId');

        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::never())->method('configureScope');

        (new SentryRequestScopeSubscriber($hub, $audit))->onKernelRequest($this->requestEvent(HttpKernelInterface::SUB_REQUEST));
    }

    private function requestEvent(int $requestType): RequestEvent
    {
        return new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            $requestType,
        );
    }
}
