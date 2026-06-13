<?php

declare(strict_types=1);

namespace App\Tests\Support\Kernel;

use App\Tests\Support\Db\DbReset;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

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
        $wasBooted = static::$booted;

        (new DbReset())->reset($this->em());

        if (!$wasBooted) {
            static::ensureKernelShutdown();
        }
    }

    protected function setClientSessionValue(KernelBrowser $client, string $key, mixed $value): void
    {
        $session = $client->getContainer()->get('session.factory')->createSession();
        $cookie = $client->getCookieJar()->get($session->getName());

        if (null !== $cookie) {
            $session->setId($cookie->getValue());
        }

        $session->set($key, $value);
        $session->save();

        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
    }
}
