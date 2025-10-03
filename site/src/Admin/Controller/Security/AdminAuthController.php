<?php

namespace App\Admin\Controller\Security;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

#[Route('/admin', name: 'admin_auth_')]
final class AdminAuthController extends AbstractController
{
    #[Route('/login', name: 'login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authUtils): Response
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('admin_dashboard');
        }

        $error = $authUtils->getLastAuthenticationError();

        return $this->render('admin/security/login.html.twig', [
            'last_username' => $authUtils->getLastUsername(),
            'error' => $error,
            'error_message' => $error ? 'Неверный логин или пароль' : null,
        ]);
    }
}
