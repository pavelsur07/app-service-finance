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

    #[\PHPUnit\Framework\Attributes\DataProvider('namedRouteErrors')]
    public function testExplicitJsonRouteOutsideApiUsesDomainStatus(\Throwable $error, int $status, string $code): void
    {
        $request = Request::create('/finance/cash-transactions/id/create-from');
        $request->attributes->set('_route', 'cash_transaction_create_from');
        $event = new ExceptionEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $error);
        (new CashExceptionListener())->onKernelException($event);
        self::assertSame($status, $event->getResponse()?->getStatusCode());
        self::assertSame($code, json_decode((string) $event->getResponse()?->getContent(), true)['error']['code']);
    }

    public static function namedRouteErrors(): iterable
    {
        yield [new \App\Cash\Exception\CashTransactionNotFoundException(), 404, 'cash_transaction_not_found'];
        yield [new \App\Cash\Exception\CashInvalidCsrfException(), 403, 'cash_invalid_csrf'];
        yield [new \App\Cash\Exception\CashDocumentOperationException('Недопустимо.'), 422, 'cash_document_operation_not_allowed'];
        yield [new \App\Cash\Exception\CashTransactionMultiSplitException(), 422, 'cash_transaction_multi_split'];
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
