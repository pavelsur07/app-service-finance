<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\Security\ApiPrincipal;
use App\Ingestion\Infrastructure\Http\CompanyFilterRequestSubscriber;
use App\Shared\Audit\AuditContextProvider;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class StatelessCompanyContextTest extends TestCase
{
    public function testAuditContextDoesNotReadSessionForApiPrincipal(): void
    {
        $principal = new ApiPrincipal('company-uuid', 100025, 'key-uuid', new \DateTimeImmutable());
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($principal);
        $active = $this->createMock(ActiveCompanyService::class);
        $active->expects(self::never())->method('getActiveCompany');
        $stack = new RequestStack();
        $stack->push(Request::create('/api/external/v1/auth/check'));
        $provider = new AuditContextProvider($security, $active, $stack);
        self::assertNull($provider->getActorUserId());
        self::assertSame('company-uuid', $provider->getCompanyId());
    }

    public function testIngestionSessionFilterSkipsApiPrincipal(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(new ApiPrincipal('company-uuid', 100025, 'key-uuid', new \DateTimeImmutable()));
        $active = $this->createMock(ActiveCompanyService::class);
        $active->expects(self::never())->method('getActiveCompany');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getFilters');
        $subscriber = new CompanyFilterRequestSubscriber($em, $security, $active);
        $subscriber->onKernelRequest(new RequestEvent($this->createMock(HttpKernelInterface::class), Request::create('/api/external/v1/auth/check'), HttpKernelInterface::MAIN_REQUEST));
    }
}
