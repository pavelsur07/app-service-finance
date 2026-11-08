<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;

/**
 * Stage 3 (twig-ui-systematization): `partials/_page_header` — единственная Tabler-разметка шапки страницы;
 * пустой блок actions не оставляет колонки, параметры container/print/class управляют обёрткой.
 */
final class PageHeaderPartialTest extends KernelTestCase
{
    public function testIncludeRendersTitleAndPretitleWithoutActionsColumn(): void
    {
        $html = $this->render("{% include 'partials/_page_header.html.twig' with {title: 'ЦФО', pretitle: 'Справочники'} only %}");

        self::assertStringContainsString('<div class="page-header d-print-none">', $html);
        self::assertStringContainsString('<div class="container-xl">', $html);
        self::assertStringContainsString('<div class="row g-2 align-items-center">', $html);
        self::assertStringContainsString('<div class="page-pretitle">Справочники</div>', $html);
        self::assertStringContainsString('<h2 class="page-title">ЦФО</h2>', $html);
        self::assertStringNotContainsString('col-auto', $html);
    }

    public function testEmbedBlocksRenderActionsColumnSubtitleAndMarkupTitle(): void
    {
        $html = $this->render(<<<'TWIG'
            {% embed 'partials/_page_header.html.twig' with {class: 'mb-3'} %}
                {% block pretitle %}<a href="/back">Назад</a>{% endblock %}
                {% block title %}Участники <b>ООО</b>{% endblock %}
                {% block subtitle %}<div class="text-secondary mt-1">Период</div>{% endblock %}
                {% block actions %}<a class="btn btn-primary" href="/new">Добавить</a>{% endblock %}
            {% endembed %}
            TWIG);

        self::assertStringContainsString('<div class="page-header d-print-none mb-3">', $html);
        self::assertStringContainsString('<div class="page-pretitle"><a href="/back">Назад</a></div>', $html);
        self::assertStringContainsString('<h2 class="page-title">Участники <b>ООО</b></h2>', $html);
        self::assertMatchesRegularExpression('~</h2>\s*<div class="text-secondary mt-1">Период</div>~', $html);
        self::assertStringContainsString('<div class="col-auto ms-auto d-print-none"><a class="btn btn-primary" href="/new">Добавить</a></div>', $html);
    }

    public function testConditionalActionsThatRenderNothingLeaveNoColumn(): void
    {
        $html = $this->render(<<<'TWIG'
            {% embed 'partials/_page_header.html.twig' with {title: 'Реклама', container: false, print: true} %}
                {% block actions %}{% if false %}<a href="/x">x</a>{% endif %}{% endblock %}
            {% endembed %}
            TWIG);

        self::assertStringContainsString('<div class="page-header">', $html);
        self::assertStringNotContainsString('container-xl', $html);
        self::assertStringNotContainsString('col-auto', $html);
        self::assertStringNotContainsString('d-print-none', $html);
    }

    /** Регрессия ревью PR C: страница, подключившая партиал, не должна рендерить свой h2.page-title (дубль заголовка). */
    public function testPagesUsingThePartialDoNotRenderTheirOwnPageTitle(): void
    {
        $templatesDir = self::getContainer()->getParameter('kernel.project_dir').'/templates';
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($templatesDir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!str_ends_with($file->getFilename(), '.html.twig') || '_page_header.html.twig' === $file->getFilename()) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (str_contains($source, "'partials/_page_header.html.twig'") && str_contains($source, 'class="page-title')) {
                $offenders[] = substr($file->getPathname(), \strlen($templatesDir) + 1);
            }
        }

        self::assertSame([], $offenders, 'Шаблоны с партиалом шапки и собственным page-title');
    }

    public function testTitleParameterIsEscaped(): void
    {
        $html = $this->render("{% include 'partials/_page_header.html.twig' with {title: '<b>x</b>'} only %}");

        self::assertStringContainsString('<h2 class="page-title">&lt;b&gt;x&lt;/b&gt;</h2>', $html);
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
