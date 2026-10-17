<?php

declare(strict_types=1);

namespace App\Shared\Controller\Internal;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Проверка связности и внутренней авторизации для сервисов `services/`. */
final class InternalPingController extends AbstractController
{
    #[Route('/internal/ping', name: 'internal_ping', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return $this->json(['status' => 'ok'], 200, ['Cache-Control' => 'no-store']);
    }
}
