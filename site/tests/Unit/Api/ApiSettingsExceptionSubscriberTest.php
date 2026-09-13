<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\Exception\ApiKeyPermissionsConflictException;
use App\Api\Exception\InvalidApiKeyPermissionsException;
use App\Api\Infrastructure\Http\ApiSettingsExceptionSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class ApiSettingsExceptionSubscriberTest extends TestCase
{
    #[DataProvider('permissionErrors')]
    public function testPermissionErrorsReturnTheirHttpStatus(\Throwable $error, int $status): void
    {
        $twig = new Environment(new ArrayLoader(['api/settings/error.html.twig' => '{{ message }}']));
        $event = new ExceptionEvent($this->createMock(HttpKernelInterface::class), Request::create('/settings/api/key/permissions'), HttpKernelInterface::MAIN_REQUEST, $error);
        (new ApiSettingsExceptionSubscriber($twig))->onException($event);
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
    }
}
