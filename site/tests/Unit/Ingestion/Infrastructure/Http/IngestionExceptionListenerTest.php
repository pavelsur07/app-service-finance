<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Infrastructure\Http;

use App\Ingestion\Exception\InvalidPeriodRangeException;
use App\Ingestion\Infrastructure\Http\IngestionExceptionListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class IngestionExceptionListenerTest extends TestCase
{
    public function testFormatsIngestionApiExceptionForIngestionApiPath(): void
    {
        $event = new ExceptionEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/api/ingestion/verification/coverage'),
            HttpKernelInterface::MAIN_REQUEST,
            new InvalidPeriodRangeException(),
        );

        (new IngestionExceptionListener())->onKernelException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            [
                'error' => [
                    'code' => 'invalid_period_range',
                    'message' => 'Некорректный диапазон периода',
                ],
            ],
            json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    public function testIgnoresNonIngestionPath(): void
    {
        $event = new ExceptionEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/api/marketplace'),
            HttpKernelInterface::MAIN_REQUEST,
            new InvalidPeriodRangeException(),
        );

        (new IngestionExceptionListener())->onKernelException($event);

        self::assertNull($event->getResponse());
    }
}
