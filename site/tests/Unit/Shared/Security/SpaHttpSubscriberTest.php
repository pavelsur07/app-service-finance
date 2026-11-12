<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Security;

use App\Shared\Infrastructure\Http\SpaHttpSubscriber;
use App\Shared\Security\Spa\SpaContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SpaHttpSubscriberTest extends TestCase
{
    #[DataProvider('encodedPaths')]
    public function testEncodedSpaPathsCannotReachLegacyFirewall(string $path): void
    {
        $request = Request::create('https://app.vashfindir.ru'.$path);
        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        $subscriber = new SpaHttpSubscriber(new SpaContext('account.vashfindir.ru', 'admin.vashfindir.ru'));
        $subscriber->onRequest($event);
        self::assertNotNull($event->getResponse());
        self::assertSame(404, $event->getResponse()->getStatusCode());
    }

    public function testServerErrorDoesNotExposeExceptionDetails(): void
    {
        $request = Request::create('https://account.vashfindir.ru/api/account/v1/session');
        $event = new ExceptionEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('private exception detail'));
        $subscriber = new SpaHttpSubscriber(new SpaContext('account.vashfindir.ru', 'admin.vashfindir.ru'));
        $subscriber->onException($event);
        self::assertNotNull($event->getResponse());
        self::assertSame(500, $event->getResponse()->getStatusCode());
        self::assertStringNotContainsString('private exception detail', (string) $event->getResponse()->getContent());
        self::assertSame('application/json', $event->getResponse()->headers->get('content-type'));
    }

    /** @return iterable<array{string}> */
    public static function encodedPaths(): iterable
    {
        yield ['/api/%61ccount/v1/session'];
        yield ['/%61pi/admin/v1/session'];
        yield ['/api/account%2fv1/session'];
    }
}
