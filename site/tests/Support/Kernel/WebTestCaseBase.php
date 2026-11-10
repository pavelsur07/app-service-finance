<?php

declare(strict_types=1);

namespace App\Tests\Support\Kernel;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Crawler;
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

    /**
     * Отправляет действие кнопки-триггера диалога подтверждения (partials/_confirm_modal):
     * POST на data-confirm-action с токеном и дополнительными полями, как это делает confirm_modal.js.
     */
    protected function submitConfirmTrigger(KernelBrowser $client, Crawler $trigger): Crawler
    {
        self::assertCount(1, $trigger, 'ожидалась ровно одна кнопка-триггер диалога подтверждения');
        /** @var array<string, scalar> $fields */
        $fields = json_decode($trigger->attr('data-confirm-fields') ?? '{}', true, 512, \JSON_THROW_ON_ERROR);

        return $client->request('POST', (string) $trigger->attr('data-confirm-action'), ['_token' => (string) $trigger->attr('data-confirm-token')] + $fields);
    }

    /**
     * Уникальный адрес клиента для тестов с лимитерами (регистрация, вход): счётчики
     * лежат в общем Redis-пуле и переживают прогоны, а все запросы иначе идут с 127.0.0.1.
     */
    protected function uniqueClientIp(): string
    {
        return sprintf(
            '2001:db8:%x:%x:%x:%x::1',
            random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
        );
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
