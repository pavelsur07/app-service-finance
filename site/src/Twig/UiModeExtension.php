<?php

declare(strict_types=1);

namespace App\Twig;

use App\Shared\Service\UiModeResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class UiModeExtension extends AbstractExtension
{
    public function __construct(
        private readonly UiModeResolver $resolver,
    ) {
    }

    /**
     * @return TwigFunction[]
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('ui_mode', $this->resolver->current(...)),
        ];
    }
}
