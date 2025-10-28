<?php

namespace App\Notification\Contract;

interface TemplateRendererInterface
{
    /**
     * Рендер шаблона (например Twig).
     * $template — путь: 'notifications/email/password_reset.html.twig'
     * $vars — переменные для шаблона.
     */
    public function render(string $template, array $vars = []): string;
}
