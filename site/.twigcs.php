<?php

declare(strict_types=1);

use App\Tests\Twigcs\Ruleset;
use FriendsOfTwig\Twigcs\Config\Config;
use FriendsOfTwig\Twigcs\Config\ConfigInterface;
use Symfony\Component\Finder\Finder;

// Конфиг twigcs (composer cs:twig / make site-cs-twig). Ruleset — tests/Twigcs/Ruleset.php.
$config = new Config();
$config->setRuleset(Ruleset::class);
// Порог error: предупреждения unused variable/macro не выводятся и не блокируют — без резолвера include/extends они ложные.
$config->setSeverity('error');
$config->setDisplay(ConfigInterface::DISPLAY_BLOCKING);
$config->setFinder(
    (new Finder())
        ->files()
        ->in(__DIR__.'/templates')
        ->name('*.twig')
        ->ignoreDotFiles(true)
        ->ignoreVCS(true)
);

return $config;
