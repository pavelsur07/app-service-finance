<?php

declare(strict_types=1);

namespace App\Shared\Controller;

use App\Shared\Service\UiModeResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class UiModeController extends AbstractController
{
    private const CSRF_TOKEN_ID = 'ui_mode_switch';

    #[Route('/settings/ui-mode', name: 'app_ui_mode_switch', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(Request $request, UiModeResolver $resolver): RedirectResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $mode = $request->request->getString('mode');
        if (!$resolver->supports($mode)) {
            throw new BadRequestHttpException('Invalid UI mode.');
        }

        $response = $this->redirect(
            $this->sameOriginReferer($request) ?? $this->generateUrl('app_home_index'),
            Response::HTTP_SEE_OTHER,
        );
        $response->headers->setCookie(
            Cookie::create(UiModeResolver::COOKIE_NAME, $mode)
                ->withExpires(time() + 365 * 24 * 60 * 60)
                ->withPath('/')
                ->withSecure($request->isSecure())
                ->withHttpOnly(true)
                ->withSameSite(Cookie::SAMESITE_LAX),
        );

        return $response;
    }

    private function sameOriginReferer(Request $request): ?string
    {
        $referer = $request->headers->get('referer');
        if (null === $referer) {
            return null;
        }

        $parts = parse_url($referer);
        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $port = $parts['port'] ?? ('https' === strtolower($parts['scheme']) ? 443 : 80);
        if (
            0 !== strcasecmp($parts['scheme'], $request->getScheme())
            || 0 !== strcasecmp($parts['host'], $request->getHost())
            || $port !== $request->getPort()
        ) {
            return null;
        }

        $target = $parts['path'] ?? '/';
        if (
            !str_starts_with($target, '/')
            || str_starts_with($target, '//')
            || str_starts_with($target, '/\\')
            || 1 === preg_match('/[\x00-\x1F\x7F\\\\]/', $target)
        ) {
            return null;
        }

        return isset($parts['query']) ? $target.'?'.$parts['query'] : $target;
    }
}
