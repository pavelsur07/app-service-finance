<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\Security\ApiAccess;
use App\Api\Security\ApiAccessSubscriber;
use App\Api\Security\ApiPrincipal;
use App\Api\Security\ApiRequestContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class ApiAccessSubscriberTest extends TestCase
{
    public function testConnectionPolicyCreatesOnlyServerOwnedContext(): void
    {
        $request = Request::create('/api/external/v1/auth/check');
        $request->attributes->set(ApiRequestContext::class, ['channel' => 'ui', 'userId' => 'forged']);
        $request->headers->set('X-Request-Id', 'forged');
        $event = $this->event('connection', $request);
        $this->subscriber(true)->onController($event);
        $context = $request->attributes->get(ApiRequestContext::class);
        self::assertInstanceOf(ApiRequestContext::class, $context);
        self::assertSame('api', $context->channel);
        self::assertNull($context->userId);
        self::assertSame('company', $context->companyId);
        self::assertNotSame('forged', $context->requestId);
    }

    #[DataProvider('deniedPolicies')]
    public function testMissingAuthenticationOrPolicyIsDenied(string $method, bool $authenticated): void
    {
        $this->expectException(AccessDeniedHttpException::class);
        $this->subscriber($authenticated)->onController($this->event($method, Request::create('/api/external/v1/test')));
    }

    /** @return iterable<array{string, bool}> */
    public static function deniedPolicies(): iterable
    {
        yield ['connection', false];
        yield ['missing', true];
        yield ['unknown', true];
    }

    private function subscriber(bool $authenticated): ApiAccessSubscriber
    {
        $storage = new TokenStorage();
        if ($authenticated) {
            $principal = new ApiPrincipal('company', 100025, 'key', new \DateTimeImmutable());
            $storage->setToken(new UsernamePasswordToken($principal, 'external_api', $principal->getRoles()));
        }

        return new ApiAccessSubscriber($storage);
    }

    private function event(string $method, Request $request): ControllerEvent
    {
        $controller = [new ApiPolicyFixture(), $method];
        self::assertIsCallable($controller);

        return new ControllerEvent($this->createMock(HttpKernelInterface::class), $controller, $request, HttpKernelInterface::MAIN_REQUEST);
    }
}

final class ApiPolicyFixture
{
    #[ApiAccess(ApiAccess::CONNECTION)]
    public function connection(): void
    {
    }

    public function missing(): void
    {
    }

    #[ApiAccess('unknown')]
    public function unknown(): void
    {
    }
}
