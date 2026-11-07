<?php

declare(strict_types=1);

namespace App\Tests\Integration\Settlement;

use App\Settlement\Exception\SettlementSourceInUseException;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class SettlementSourceExceptionTest extends IntegrationTestCase
{
    public function testSourceRejectionHasBusinessStatusAndStableApiError(): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $event = new ExceptionEvent($kernel, Request::create('/api/cash/transactions/example', 'POST'), HttpKernelInterface::MAIN_REQUEST, new SettlementSourceInUseException());
        self::getContainer()->get('event_dispatcher')->dispatch($event, KernelEvents::EXCEPTION);
        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('settlement_source_in_use', json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR)['error']['code']);
    }
}
