<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\Exception\ApiKeyNotFoundException;
use App\Api\Exception\ApiKeyOwnerRequiredException;
use App\Api\Exception\ApiKeyPermissionsConflictException;
use App\Api\Exception\InvalidApiKeyNameException;
use App\Api\Exception\InvalidApiKeyPermissionsException;
use App\Api\Infrastructure\Http\ApiSettingsExceptionSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\EventListener\ErrorListener;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class ApiSettingsExceptionSubscriberTest extends TestCase
{
    #[DataProvider('permissionErrors')]
    public function testPermissionErrorsReturnTheirHttpStatus(\Throwable $error, int $status): void
    {
        $twig = new Environment(new ArrayLoader(['api/settings/error.html.twig' => '{{ message }}']));
        $event = new ExceptionEvent($this->createMock(HttpKernelInterface::class), Request::create('/settings/api/key/permissions'), HttpKernelInterface::MAIN_REQUEST, $error);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('log');
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ApiSettingsExceptionSubscriber($twig));
        $dispatcher->addSubscriber(new ErrorListener(null, $logger));
        $dispatcher->dispatch($event, KernelEvents::EXCEPTION);
        self::assertTrue($event->isPropagationStopped());
        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame($status, $response->getStatusCode());
        self::assertStringContainsString($error->getMessage(), (string) $response->getContent());
    }

    /** @return iterable<array{\Throwable, int}> */
    public static function permissionErrors(): iterable
    {
        yield [new ApiKeyPermissionsConflictException(), 409];
        yield [new InvalidApiKeyPermissionsException(), 422];
        yield [new ApiKeyOwnerRequiredException(), 403];
        yield [new ApiKeyNotFoundException(), 404];
        yield [new InvalidApiKeyNameException(), 422];
    }
}
