<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Временная вкладка «Тест» — все отладочные эндпоинты в одном месте.
 * Удалить после завершения тестирования.
 */
#[Route('/marketplace/test')]
#[IsGranted('ROLE_USER')]
final class TestDebugController extends AbstractController
{
    #[Route('', name: 'marketplace_test', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('marketplace/test.html.twig');
    }
}
