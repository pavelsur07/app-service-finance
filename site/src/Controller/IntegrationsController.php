<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class IntegrationsController extends AbstractController
{
    #[Route('/integrations/marketplace', name: 'integrations_marketplace_index')]
    public function marketplace(): Response
    {
        return $this->render('integrations/marketplace.html.twig');
    }
}
