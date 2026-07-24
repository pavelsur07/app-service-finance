<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class UiThemeExtension extends AbstractExtension
{
    private const COOKIE_NAME = 'ui_theme';
    private const VF_VALUE = 'vf';

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @return TwigFunction[]
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('vf_mode', [$this, 'isVfMode']),
        ];
    }

    public function isVfMode(): bool
    {
        $request = $this->requestStack->getCurrentRequest();

        return ($request?->cookies->get(self::COOKIE_NAME) ?? 'tabler') === self::VF_VALUE;
    }
}
