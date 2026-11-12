<?php

declare(strict_types=1);

namespace App\Admin\Controller\Api\Session;

use App\Admin\Api\Response\SessionResponse;
use App\Company\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class SessionController extends AbstractController
{
    #[Route('/api/admin/v1/session', name: 'spa_admin_session', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->json((new SessionResponse((string) $user->getId(), (string) $user->getEmail()))->toArray());
    }
}
