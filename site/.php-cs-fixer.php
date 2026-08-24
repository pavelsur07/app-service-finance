<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    ->name('*.php')
    ->ignoreVCSIgnored(true);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'phpdoc_align' => ['align' => 'left'],
        'native_function_invocation' => false,
        'declare_strict_types' => true,
        // Отключено намеренно. Правило делает static замыкания, не использующие
        // $this. Оно RISKY: привязка static-замыкания к объекту невозможна, а
        // доказать, что ни одно из 61 замыкания не передаётся во vendor-код,
        // который делает bind внутри себя, можно только аудитом всех
        // потребителей. Для задачи форматирования цена не оправдана: static у
        // замыкания — микрооптимизация.
        'static_lambda' => false,
    ])
    ->setFinder($finder);
