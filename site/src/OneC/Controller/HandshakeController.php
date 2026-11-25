<?php

declare(strict_types=1);

namespace App\OneC\Controller;

use App\Company\Security\PublicAccess;
use App\OneC\Application\HandshakeAction;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[PublicAccess]
final class HandshakeController
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    #[Route('/api/onec/v1/handshake', name: 'onec_handshake', methods: ['POST'])]
    public function __invoke(Request $request, HandshakeAction $handshake): JsonResponse
    {
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/iD', $request->headers->get('Authorization', ''), $matches)) {
            return $this->error(401, 'Нужен токен подключения.');
        }
        $json = stream_get_contents($request->getContent(true), self::MAX_BYTES + 1);
        if (false === $json || \strlen($json) > self::MAX_BYTES) {
            return $this->error(413, 'Размер запроса превышает 10 МиБ.');
        }
        try {
            return new JsonResponse($handshake($matches[1], $json));
        } catch (\JsonException|\InvalidArgumentException $e) {
            return $this->error(422, $e instanceof \JsonException ? 'Некорректный JSON.' : $e->getMessage());
        } catch (UniqueConstraintViolationException) {
            return $this->error(409, 'Эта база уже привязана к подключению.');
        } catch (\DomainException $e) {
            return $this->error($e->getCode(), $e->getMessage());
        }
    }

    private function error(int $status, string $message): JsonResponse
    {
        $code = match ($status) {
            401 => 'invalid_token',
            403 => 'disabled',
            409 => 'base_conflict',
            413 => 'payload_too_large',
            422 => 'invalid_handshake',
            429 => 'rate_limited',
            default => throw new \LogicException('Неизвестный код отказа handshake.'),
        };

        return new JsonResponse(['error' => $code, 'message' => $message], $status, 429 === $status ? ['Retry-After' => '60'] : []);
    }
}
