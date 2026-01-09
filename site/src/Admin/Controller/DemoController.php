<?php

namespace App\Admin\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
class DemoController extends AbstractController
{
    #[Route('/demo', name: 'admin_demo', methods: ['GET'])]
    public function demoOne(): Response
    {
        return $this->render('templates/finance/scenario/demo.html.twig');
    }
}
