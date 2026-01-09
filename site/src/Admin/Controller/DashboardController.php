<?php

namespace App\Admin\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin', name: 'admin_')]
final class DashboardController extends AbstractController
{
    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/dashboard/index.html.twig', [
            'title' => 'Admin · Dashboard (тестовая страница)',
        ]);
    }
}
