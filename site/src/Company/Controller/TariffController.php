<?php

namespace App\Company\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TariffController extends AbstractController
{
    #[Route('/company/price', name: 'company_price_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('company/price/index.html.twig');
    }
}
