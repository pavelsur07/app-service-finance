<?php
declare(strict_types=1);

use FriendsOfTwig\Twigcs\Config\Config;
use FriendsOfTwig\Twigcs\Ruleset\Official;
use Symfony\Component\Finder\Finder;

// Мажорная версия Twig (например, 3)
$version   = \class_exists(\Twig\Environment::class) ? (string) \Twig\Environment::VERSION : '3.0.0';
$twigMajor = (int) explode('.', $version)[0];

$config = new Config();

// ВАЖНО: строка-класс ruleset'а
$config->setRuleset(Official::class);

// В новых версиях — setTwigMajorVersion(); в более старых — setTwigVersion()
if (method_exists($config, 'setTwigMajorVersion')) {
    $config->setTwigMajorVersion($twigMajor);
} elseif (method_exists($config, 'setTwigVersion')) {
    $config->setTwigVersion($twigMajor);
}

// Используем Symfony Finder вместо FriendsOfTwig\Twigcs\Finder
$finder = (new Finder())
    ->files()
    ->in(__DIR__ . '/templates')
    ->name('*.twig')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true)
    ->exclude(['vendor', 'var', 'node_modules']);

$config->setFinder($finder);

return $config;
