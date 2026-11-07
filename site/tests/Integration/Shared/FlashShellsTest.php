<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

/**
 * Stage 3 (twig-ui-systematization): три shell'а рендерят flash одним маппингом label → variant,
 * служебные ключи flash (invite_token) shell не трогает.
 */
final class FlashShellsTest extends KernelTestCase
{
    /** @var array<string, string> label → variant */
    private const LABEL_VARIANTS = [
        'success' => 'success',
        'error' => 'danger',
        'danger' => 'danger',
        'warning' => 'warning',
        'alert' => 'warning',
        'notice' => 'info',
        'info' => 'info',
    ];

    /** @var array<string, string> шаблон shell'а → CSS-класс варианта, %s = variant */
    private const SHELLS = [
        'partials/_flash.html.twig' => 'toast text-bg-%s',
        'admin/_toasts.html.twig' => 'toast toast--%s',
        'app/_ui/_flash.html.twig' => 'alert alert--%s',
    ];

    public function testEveryShellMapsLabelsToTheSameVariant(): void
    {
        foreach (self::SHELLS as $template => $classPattern) {
            $session = $this->sessionWithFlashes();
            $html = $this->render($template, $session);

            foreach (self::LABEL_VARIANTS as $label => $variant) {
                $message = \sprintf('Сообщение %s', $label);
                $pattern = \sprintf('~class="%s"[^>]*>(?:(?!class="%s").)*%s~s', preg_quote(\sprintf($classPattern, $variant), '~'), preg_quote(explode(' ', $classPattern)[0], '~'), preg_quote($message, '~'));
                self::assertMatchesRegularExpression($pattern, $html, \sprintf('%s: label "%s" → variant "%s"', $template, $label, $variant));
            }

            self::assertSame([], $session->getFlashBag()->peekAll(), \sprintf('%s: все известные label прочитаны', $template));
        }
    }

    public function testShellLeavesServiceFlashKeysToThePage(): void
    {
        foreach (array_keys(self::SHELLS) as $template) {
            $session = $this->sessionWithFlashes();
            $session->getFlashBag()->add('invite_token', 'secret-token');

            $html = $this->render($template, $session);

            self::assertStringNotContainsString('secret-token', $html, $template);
            self::assertSame(['invite_token' => ['secret-token']], $session->getFlashBag()->peekAll(), $template);
        }
    }

    private function sessionWithFlashes(): Session
    {
        $session = new Session(new MockArraySessionStorage());
        foreach (array_keys(self::LABEL_VARIANTS) as $label) {
            $session->getFlashBag()->add($label, \sprintf('Сообщение %s', $label));
        }

        return $session;
    }

    private function render(string $template, Session $session): string
    {
        $request = Request::create('/');
        $request->setSession($session);

        $requestStack = self::getContainer()->get(RequestStack::class);
        $requestStack->push($request);

        try {
            return self::getContainer()->get(Environment::class)->render($template);
        } finally {
            $requestStack->pop();
        }
    }
}
