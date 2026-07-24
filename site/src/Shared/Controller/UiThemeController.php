<?php

declare(strict_types=1);

namespace App\Shared\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class UiThemeController extends AbstractController
{
    #[Route('/settings/ui-theme', name: 'app_ui_theme_switch', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(Request $request): JsonResponse
    {
        $requested = $request->request->get('theme', '');
        $theme = in_array($requested, ['tabler', 'vf'], true) ? $requested : 'tabler';

        $response = new JsonResponse(['ok' => true, 'theme' => $theme]);
        $response->headers->setCookie(
            Cookie::create('ui_theme', $theme)
                ->withExpires(time() + 365 * 24 * 3600)
                ->withPath('/')
                ->withSecure($request->isSecure())
                ->withSameSite(Cookie::SAMESITE_LAX)
        );

        return $response;
    }
}
