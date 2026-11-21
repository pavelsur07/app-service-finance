<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\OAuth;

use League\OAuth2\Server\Exception\OAuthServerException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimit;

final readonly class OAuthHttp
{
    public const PRIVATE_HEADERS = ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'];

    /** @return array<string, string> */
    public static function parameters(Request $request): array
    {
        if ($request->isMethod('GET')) {
            $raw = $request->server->getString('QUERY_STRING');
            $parameters = $request->query->all();
        } else {
            $raw = $request->getContent();
            $parameters = $request->request->all();
            $mediaType = strtolower(trim(explode(';', $request->headers->get('Content-Type', ''), 2)[0]));
            if ($request->headers->has('Authorization') || 'application/x-www-form-urlencoded' !== $mediaType) {
                throw OAuthServerException::invalidRequest('form');
            }
        }

        if (8192 < \strlen($raw) || 16 < \count($parameters)) {
            throw OAuthServerException::invalidRequest('request');
        }

        $seen = [];
        foreach (explode('&', $raw) as $pair) {
            if ('' === $pair) {
                continue;
            }
            $name = urldecode(explode('=', $pair, 2)[0]);
            if (isset($seen[$name]) || !\array_key_exists($name, $parameters)) {
                throw OAuthServerException::invalidRequest('request');
            }
            $seen[$name] = true;
        }

        $scalars = [];
        foreach ($parameters as $name => $value) {
            if (!\is_string($value) || 4096 < \strlen($value) || 1 !== preg_match('/^[\x20-\x7E]*$/D', $value)) {
                throw OAuthServerException::invalidRequest('request');
            }
            $scalars[$name] = $value;
        }

        return $scalars;
    }

    public static function error(OAuthServerException $error): JsonResponse
    {
        return new JsonResponse(['error' => $error->getErrorType()], $error->getHttpStatusCode(), self::PRIVATE_HEADERS);
    }

    public static function rateLimited(RateLimit $limit): JsonResponse
    {
        return new JsonResponse(['error' => 'temporarily_unavailable'], 429, self::PRIVATE_HEADERS + [
            'Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()),
        ]);
    }

    public static function privateResponse(Response $response): Response
    {
        $response->headers->add(self::PRIVATE_HEADERS);

        return $response;
    }
}
