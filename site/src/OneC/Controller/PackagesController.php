<?php

declare(strict_types=1);

namespace App\OneC\Controller;

use App\Company\Security\PublicAccess;
use App\OneC\Application\ReceivePackageAction;
use App\OneC\Exception\OneCApiException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[PublicAccess]
final class PackagesController
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    #[Route('/api/onec/v1/packages', name: 'onec_packages', methods: ['POST'])]
    public function __invoke(Request $request, ReceivePackageAction $receive): JsonResponse
    {
        try {
            if (!preg_match('/^Bearer ([a-f0-9]{64})$/iD', $request->headers->get('Authorization', ''), $matches)) {
                throw new OneCApiException(401, 'invalid_token', 'Нужен токен подключения.');
            }

            return new JsonResponse($receive($matches[1], static function () use ($request): string {
                $body = stream_get_contents($request->getContent(true), self::MAX_BYTES + 1);
                if (false === $body || \strlen($body) > self::MAX_BYTES) {
                    throw new OneCApiException(413, 'payload_too_large', 'Размер части превышает 10 МиБ.');
                }

                return $body;
            }));
        } catch (OneCApiException $e) {
            return new JsonResponse(['error' => $e->error, 'message' => $e->getMessage()], $e->status, 429 === $e->status ? ['Retry-After' => '60'] : []);
        }
    }
}
