<?php

// src/Notification/Template/TwigTemplateRenderer.php

namespace App\Notification\Template;

use App\Notification\Contract\TemplateRendererInterface;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

final class TwigTemplateRenderer implements TemplateRendererInterface
{
    public function __construct(private Environment $twig)
    {
    }

    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function render(string $template, array $vars = []): string
    {
        return $this->twig->render($template, $vars);
    }
}
