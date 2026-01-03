<?php

declare(strict_types=1);

namespace App\Tests\_support\Kernel;

use App\Tests\_support\Db\DbReset;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Базовый класс для web-тестов (builders-first);
 * работа с БД остаётся явной и не скрывается.
 */
abstract class WebTestCaseBase extends WebTestCase
{
    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function resetDb(): void
    {
        (new DbReset())->reset($this->em());
    }
}
