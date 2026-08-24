<?php

// Отдельный гейт ровно под одно правило: declare(strict_types=1) в каждом файле.
//
// Зачем отдельно от .php-cs-fixer.php: общий cs:check хронически красный
// (506 файлов из 2342 по другим правилам @Symfony) и возвращает exit 8
// независимо от того, есть нарушение declare_strict_types или нет. Для CI такой
// гейт бесполезен — он не различает норму и регресс.
//
// Этот конфиг достижимо зелёный: на 2026-08-24 Found 0 of 2342, exit 0.
//
// Охват: PHP-файлы site/src и site/tests, кроме исключённых правилами VCS.
// Вне охвата остаются site/config (3 файла), site/public/index.php и
// site/migrations — точки входа и конфиги, где declare не принят.

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    ->name('*.php')
    ->ignoreVCSIgnored(true);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        'declare_strict_types' => true,
    ])
    ->setFinder($finder);
