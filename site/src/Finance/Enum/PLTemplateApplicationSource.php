<?php

declare(strict_types=1);

namespace App\Finance\Enum;

/**
 * Откуда применён шаблон ОПиУ.
 */
enum PLTemplateApplicationSource: string
{
    case ONBOARDING = 'onboarding'; // создание компании и дозаполнение
    case SCREEN = 'screen';         // «Базовый шаблон ОПиУ» на экране импорта
    case COMMAND = 'command';       // app:finance:pl-tree:apply-default
    case MARKETPLACE_EXTENSION = 'mp_extension'; // статьи маркетплейса в «Базовом» дереве
}
