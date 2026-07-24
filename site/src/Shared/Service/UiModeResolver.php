<?php

declare(strict_types=1);

namespace App\Shared\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class UiModeResolver
{
    public const APP = 'app';
    public const COOKIE_NAME = 'ui_mode';
    public const LEGACY = 'legacy';

    private const LEGACY_COOKIE_NAME = 'ui_theme';
    private const LEGACY_COOKIE_APP_VALUE = 'vf';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function current(): string
    {
        $request = $this->requestStack->getCurrentRequest();

        return null === $request ? self::LEGACY : $this->resolve($request);
    }

    public function resolve(Request $request): string
    {
        if (!$this->authorizationChecker->isGranted('ROLE_USER')) {
            return self::LEGACY;
        }

        $cookies = $request->cookies->all();
        $mode = $cookies[self::COOKIE_NAME] ?? '';
        $mode = \is_string($mode) ? $mode : '';

        if ($this->supports($mode)) {
            return $mode;
        }

        $legacyMode = $cookies[self::LEGACY_COOKIE_NAME] ?? '';
        if ('' === $mode && self::LEGACY_COOKIE_APP_VALUE === $legacyMode) {
            return self::APP;
        }

        return self::LEGACY;
    }

    public function supports(string $mode): bool
    {
        return \in_array($mode, [self::LEGACY, self::APP], true);
    }
}
