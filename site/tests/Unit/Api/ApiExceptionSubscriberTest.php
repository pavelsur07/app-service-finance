<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\Infrastructure\Http\ApiExceptionSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

final class ApiExceptionSubscriberTest extends TestCase
{
    public function testUnexpectedErrorRetainsThrowableForMonitoringButNotClient(): void
    {
        $error = new \RuntimeException('Internal implementation detail');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('External API request failed.', ['exception' => $error, 'status' => 500]);
        $event = $this->event($error);
        (new ApiExceptionSubscriber($logger))->onException($event);
        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString($error->getMessage(), (string) $response->getContent());
    }

    public function testAuthenticationFailureReturnsBearerChallenge(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $event = $this->event(new AuthenticationException('Authentication failed.'));
        (new ApiExceptionSubscriber($logger))->onException($event);
        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer', $response->headers->get('WWW-Authenticate'));
    }

    private function event(\Throwable $error): ExceptionEvent
    {
        return new ExceptionEvent($this->createMock(HttpKernelInterface::class), Request::create('/api/external/v1/auth/check'), HttpKernelInterface::MAIN_REQUEST, $error);
    }
}
