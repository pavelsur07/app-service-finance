<?php

declare(strict_types=1);

namespace App\Tests\Unit\Balance\Infrastructure;

use App\Balance\Exception\BalanceLedgerException;
use App\Balance\Infrastructure\Http\BalanceExceptionListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class BalanceExceptionListenerTest extends TestCase
{
    public function testProgrammingErrorsAreLeftForNormalErrorHandling(): void
    {
        $listener = new BalanceExceptionListener(new Environment(new ArrayLoader()));
        foreach ([new \InvalidArgumentException('internal assertion'), new \DomainException('unexpected internal invariant')] as $error) {
            $event = $this->event($error);
            $listener->onException($event);
            self::assertNull($event->getResponse());
            self::assertFalse($event->isPropagationStopped());
        }
    }

    public function testKnownValidationErrorsKeepStatusAndRunAfterLogger(): void
    {
        $listener = new BalanceExceptionListener(new Environment(new ArrayLoader()));
        $event = $this->event(new BalanceLedgerException('Конфликт версии.', 409));
        $listener->onException($event);
        self::assertSame(409, $event->getResponse()?->getStatusCode());
        self::assertSame(['error' => ['code' => 'balance_conflict', 'message' => 'Конфликт версии.']], json_decode((string) $event->getResponse()->getContent(), true));
        self::assertLessThan(0, BalanceExceptionListener::getSubscribedEvents()[KernelEvents::EXCEPTION][1]);
    }

    private function event(\Throwable $error): ExceptionEvent
    {
        $request = Request::create('/balance/documents');
        $request->headers->set('Accept', 'application/json');

        return new ExceptionEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $error);
    }
}
