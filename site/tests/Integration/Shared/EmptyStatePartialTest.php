<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;

/**
 * Stage 3 (twig-ui-systematization): `partials/_empty` — единственная разметка пустого состояния;
 * обе ветки (Tabler / DS) отдают прежние классы, блоки icon/action/attrs необязательны.
 */
final class EmptyStatePartialTest extends KernelTestCase
{
    public function testLegacyVariantWithoutIconRendersTablerMarkupOnly(): void
    {
        $html = $this->render("{% include 'partials/_empty.html.twig' with {title: 'Нет записей', hint: 'Добавьте первую'} only %}");

        self::assertStringContainsString('<div class="empty">', $html);
        self::assertStringContainsString('<p class="empty-title">Нет записей</p>', $html);
        self::assertStringContainsString('<p class="empty-subtitle text-muted">Добавьте первую</p>', $html);
        self::assertStringNotContainsString('empty-icon', $html);
        self::assertStringNotContainsString('empty-text', $html);
    }

    public function testAppVariantRendersDsMarkupAndOptionalBlocks(): void
    {
        $html = $this->render(<<<'TWIG'
            {% embed 'partials/_empty.html.twig' with {title: 'Ботов нет', hint: 'Нажмите «Добавить»', variant: 'app'} %}
                {% block attrs %} data-coverage-empty{% endblock %}
                {% block icon %}<svg width="22" height="22"></svg>{% endblock %}
                {% block action %}<a class="btn btn-md btn-primary" href="/new">Добавить</a>{% endblock %}
            {% endembed %}
            TWIG);

        self::assertStringContainsString('<div class="empty" data-coverage-empty>', $html);
        self::assertStringContainsString('<div class="empty-icon"><svg width="22" height="22"></svg></div>', $html);
        self::assertStringContainsString('<div class="empty-title">Ботов нет</div>', $html);
        self::assertStringContainsString('<div class="empty-text">Нажмите «Добавить»</div>', $html);
        self::assertStringContainsString('<a class="btn btn-md btn-primary" href="/new">Добавить</a>', $html);
        self::assertStringNotContainsString('empty-subtitle', $html);
    }

    public function testTitleAndHintAreEscapedAndHintIsOptional(): void
    {
        $html = $this->render("{% include 'partials/_empty.html.twig' with {title: '<b>x</b>'} only %}");

        self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        self::assertStringNotContainsString('empty-subtitle', $html);
    }

    private function render(string $source): string
    {
        $twig = self::getContainer()->get(Environment::class);
        $loader = $twig->getLoader();
        $twig->setLoader(new ChainLoader([new ArrayLoader(['__test__' => $source]), $loader]));

        try {
            return $twig->render('__test__');
        } finally {
            $twig->setLoader($loader);
        }
    }
}
