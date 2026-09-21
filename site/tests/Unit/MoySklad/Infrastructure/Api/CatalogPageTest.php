<?php

declare(strict_types=1);

namespace App\Tests\Unit\MoySklad\Infrastructure\Api;

use App\MoySklad\Exception\CatalogSyncException;
use App\MoySklad\Infrastructure\Api\MoySkladClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CatalogPageTest extends TestCase
{
    public function testLeavesGzipNegotiationAndInflationToSymfony(): void
    {
        $body = file_get_contents(__DIR__.'/../../../../Fixtures/MoySklad/Variant/variants_archived_empty.json');
        self::assertIsString($body);
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($body): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('https://example.test/api/remap/1.2/entity/variant?limit=2&offset=0&order=id%2Casc&filter=archived%3Dtrue', $url);
            // Symfony adds gzip itself and inflates only when callers leave this header unset.
            self::assertArrayNotHasKey('accept-encoding', $options['normalized_headers']);
            self::assertSame(0, $options['max_redirects']);

            return new MockResponse($body);
        });
        $page = (new MoySkladClient($http, 'https://example.test/api/remap/1.2'))->fetchCatalogPage('test-secret', 'variant', true, 0, 2);
        self::assertSame([], $page['rows']);
    }

    #[DataProvider('errors')]
    public function testClassifiesFailuresWithoutLeakingBody(int $status, string $expected): void
    {
        $http = new MockHttpClient(new MockResponse('private-response', ['http_code' => $status]));
        try {
            (new MoySkladClient($http, 'https://example.test/api/remap/1.2'))->fetchCatalogPage('private-token', 'product', false, 0);
            self::fail('Request should fail.');
        } catch (CatalogSyncException $error) {
            self::assertSame($expected, $error->category);
            self::assertStringNotContainsString('private-', $error->getMessage());
        }
    }

    /** @return iterable<string, array{int, string}> */
    public static function errors(): iterable
    {
        yield '401' => [401, 'auth'];
        yield '403' => [403, 'forbidden'];
        yield '429' => [429, 'rate_limited'];
        yield '503' => [503, 'temporary'];
    }
}
