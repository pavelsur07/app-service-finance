<?php

declare(strict_types=1);

namespace App\Tests\Support\Kernel;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Базовый класс для web-тестов (builders-first);
 * работа с БД остаётся явной и не скрывается.
 *
 * Изоляция между тестами — транзакция DAMA\DoctrineTestBundle (rollback после
 * каждого теста), чистить БД в тесте не нужно. Последовательности при откате
 * не сбрасываются: не проверяйте конкретные значения автоинкремента (например,
 * publicId).
 */
abstract class WebTestCaseBase extends WebTestCase
{
    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
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

    protected function csrfToken(KernelBrowser $client, string $tokenId): string
    {
        $session = $client->getContainer()->get('session.factory')->createSession();
        $cookie = $client->getCookieJar()->get($session->getName());
        if (null !== $cookie) {
            $session->setId($cookie->getValue());
        }

        $request = Request::create('/');
        $request->setSession($session);
        $requestStack = $client->getContainer()->get('request_stack');
        $requestStack->push($request);

        try {
            $token = $client->getContainer()->get('security.csrf.token_manager')->getToken($tokenId)->getValue();
            $session->save();
            $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));

            return $token;
        } finally {
            $requestStack->pop();
        }
    }
}
