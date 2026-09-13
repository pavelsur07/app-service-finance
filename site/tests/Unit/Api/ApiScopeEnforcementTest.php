<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\Security\ApiAccessSubscriber;
use App\Api\Security\ApiPrincipal;
use App\Api\Security\ApiRequestContext;
use App\Tests\Functional\Api\Fixtures\PermissionController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class ApiScopeEnforcementTest extends TestCase
{
    #[DataProvider('scopePairs')]
    public function testEachScopeAuthorizesOnlyItsOwnEndpoint(string $required, string $granted): void
    {
        $event = $this->event(str_replace('.', '_', $required));
        if ($required !== $granted) {
            $this->expectException(AccessDeniedHttpException::class);
        }
        $this->subscriber([$granted])->onController($event);
        self::assertInstanceOf(ApiRequestContext::class, $event->getRequest()->attributes->get(ApiRequestContext::class));
    }

    #[DataProvider('unavailablePolicies')]
    public function testMissingUnknownAndAbsentScopesDenyEvenOtherEffectiveScopes(string $method): void
    {
        $this->expectException(AccessDeniedHttpException::class);
        $this->subscriber(['unknown.read', 'accounts.read'])->onController($this->event($method));
    }

    /** @return iterable<string, array{string, string}> */
    public static function scopePairs(): iterable
    {
        $scopes = [
            'accounts.read', 'counterparties.read', 'cash_categories.read', 'pl_categories.read',
            'projects.read', 'responsibility_centers.read',
            'cash_transactions.read', 'cash_transactions.create', 'cash_transactions.update', 'cash_transactions.soft_delete',
            'pl_operations.read', 'pl_operations.create', 'pl_operations.update', 'pl_operations.soft_delete',
            'cashflow_reports.read', 'pl_reports.read',
        ];
        foreach ($scopes as $required) {
            foreach ($scopes as $granted) {
                yield $required.' with '.$granted => [$required, $granted];
            }
        }
    }

    /** @return iterable<array{string}> */
    public static function unavailablePolicies(): iterable
    {
        yield ['missing'];
        yield ['unknown'];
        yield ['cash_transactions_create'];
    }

    /** @param list<string> $effective */
    private function subscriber(array $effective): ApiAccessSubscriber
    {
        $principal = new ApiPrincipal('company', 100025, 'key', new \DateTimeImmutable(), [], $effective);
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken($principal, 'external_api', $principal->getRoles()));

        return new ApiAccessSubscriber($storage);
    }

    private function event(string $method): ControllerEvent
    {
        $controller = [new PermissionController(), $method];
        self::assertIsCallable($controller);

        return new ControllerEvent($this->createMock(HttpKernelInterface::class), $controller, Request::create('/api/external/v1/__test/permissions/'.$method), HttpKernelInterface::MAIN_REQUEST);
    }
}
