<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Infrastructure\Http;

use App\Cash\Exception\BalanceNotAvailableBeforeOpeningDateException;
use App\Cash\Infrastructure\Http\CashExceptionListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class CashExceptionListenerTest extends TestCase
{
    public function testFormatsCashApiExceptionAsUnprocessableEntity(): void
    {
        $event = new ExceptionEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/api/cash/balances'),
            HttpKernelInterface::MAIN_REQUEST,
            new BalanceNotAvailableBeforeOpeningDateException(),
        );

        (new CashExceptionListener())->onKernelException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            [
                'error' => [
                    'code' => 'balance_not_available_before_opening_date',
                    'message' => 'Остаток недоступен до даты ввода начального сальдо.',
                ],
            ],
            json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    public function testIgnoresHtmlRequest(): void
    {
        $event = new ExceptionEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/accounts/example/edit'),
            HttpKernelInterface::MAIN_REQUEST,
            new BalanceNotAvailableBeforeOpeningDateException(),
        );

        (new CashExceptionListener())->onKernelException($event);

        self::assertNull($event->getResponse());
    }
}
