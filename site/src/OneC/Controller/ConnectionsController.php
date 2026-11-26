<?php

declare(strict_types=1);

namespace App\OneC\Controller;

use App\Company\Entity\User;
use App\OneC\Infrastructure\Query\ConnectionQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ConnectionsController extends AbstractController
{
    #[Route('/onec/connections', name: 'onec_connections', methods: ['GET'])]
    public function __invoke(#[CurrentUser] User $user, ConnectionQuery $connections, ClockInterface $clock): Response
    {
        return $this->render('onec/index.html.twig', ['connections' => $connections->listOwned((string) $user->getId()), 'now' => $clock->now()]);
    }
}
