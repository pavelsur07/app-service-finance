<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class IntegrationsController extends AbstractController
{
    #[Route('/integrations/marketplace', name: 'integrations_marketplace_index')]
    public function marketplace(): Response
    {
        return $this->render('integrations/marketplace.html.twig');
    }
}
