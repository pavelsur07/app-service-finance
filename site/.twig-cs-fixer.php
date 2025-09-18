<?php

use FriendsOfTwig\Twigcs\Config\Config;
use FriendsOfTwig\Twigcs\Ruleset\Official;

return (new Config())
    ->setSeverity('warning') // 'warning' или 'error'
    ->setRuleset(new Official())
    ->setPaths([__DIR__ . '/templates']);
