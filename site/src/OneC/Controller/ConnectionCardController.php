<?php

declare(strict_types=1);

namespace App\OneC\Controller;

use App\Company\Entity\User;
use App\OneC\Exception\ConnectionNotFoundException;
use App\OneC\Http\ConnectionCardPage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ConnectionCardController extends AbstractController
{
    #[Route('/onec/connections/{id}', name: 'onec_connection_card', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function __invoke(string $id, #[CurrentUser] User $user, ConnectionCardPage $page): Response
    {
        try {
            return $page->render((string) $user->getId(), $id);
        } catch (ConnectionNotFoundException) {
            throw $this->createNotFoundException();
        }
    }
}
