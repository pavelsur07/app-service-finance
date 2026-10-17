<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Security\Internal;

use App\Shared\Security\Internal\InternalApiPrivacySubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\HttpKernel\Profiler\Profiler;

final class InternalApiPrivacySubscriberTest extends TestCase
{
    private string $storageDir;

    protected function setUp(): void
    {
        $this->storageDir = sys_get_temp_dir().'/internal-privacy-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageDir)) {
            exec('rm -rf '.escapeshellarg($this->storageDir));
        }
    }

    #[DataProvider('paths')]
    public function testProfileIsWrittenOnlyOutsideInternalApi(string $path, bool $profiled): void
    {
        $profiler = new Profiler(new FileProfilerStorage('file:'.$this->storageDir));
        $request = Request::create($path, server: ['HTTP_AUTHORIZATION' => 'Bearer secret-token']);

        (new InternalApiPrivacySubscriber($profiler))->onRequest(
            new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST),
        );

        self::assertSame($profiled, null !== $profiler->collect($request, new Response()));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function paths(): iterable
    {
        yield 'internal ping' => ['/internal/ping', false];
        yield 'internal connections' => ['/internal/marketplace-connections', false];
        yield 'regular page' => ['/marketplace', true];
        yield 'lookalike prefix' => ['/internals', true];
    }

    public function testWithoutProfilerDoesNothing(): void
    {
        $this->expectNotToPerformAssertions();

        (new InternalApiPrivacySubscriber())->onRequest(
            new RequestEvent($this->createStub(HttpKernelInterface::class), Request::create('/internal/ping'), HttpKernelInterface::MAIN_REQUEST),
        );
    }
}
