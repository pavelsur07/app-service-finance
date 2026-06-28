<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Sentry;

use App\Shared\Infrastructure\Sentry\EventScrubber;
use PHPUnit\Framework\TestCase;
use Sentry\Event;

final class EventScrubberTest extends TestCase
{
    private const REDACTED = '[Filtered]';

    public function testScrubsSensitiveKeysInExtraRecursively(): void
    {
        $event = Event::createEvent();
        $event->setExtra([
            'password' => 'secret123',
            'api_token' => 'abc',
            'safe' => 'visible',
            'nested' => [
                'authorization' => 'Bearer x',
                'client_secret' => 'csx',
                'safe_nested' => 'ok',
            ],
        ]);

        (new EventScrubber())($event);

        $extra = $event->getExtra();
        self::assertSame(self::REDACTED, $extra['password']);
        self::assertSame(self::REDACTED, $extra['api_token']);
        self::assertSame('visible', $extra['safe']);
        self::assertSame(self::REDACTED, $extra['nested']['authorization']);
        self::assertSame(self::REDACTED, $extra['nested']['client_secret']);
        self::assertSame('ok', $extra['nested']['safe_nested']);
    }

    public function testScrubsTagsByKey(): void
    {
        $event = Event::createEvent();
        $event->setTags(['token' => 'tok', 'route' => '/x']);

        (new EventScrubber())($event);

        $tags = $event->getTags();
        self::assertSame(self::REDACTED, $tags['token']);
        self::assertSame('/x', $tags['route']);
    }

    public function testScrubsRequestHeadersCookiesAndBody(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'url' => 'https://example.test/api',
            'method' => 'POST',
            'headers' => [
                'Authorization' => ['Bearer y'],
                'Accept' => ['application/json'],
            ],
            'cookies' => [
                'PHPSESSID' => 'sess-value',
                'remember_me' => 'rm-value',
            ],
            'data' => [
                'user' => ['inn' => '1234567890', 'name' => 'Ivan'],
                'access_token' => 'tok',
            ],
        ]);

        (new EventScrubber())($event);

        $request = $event->getRequest();

        // Чувствительный заголовок — вычищен, безопасный — остался.
        self::assertSame(self::REDACTED, $request['headers']['Authorization']);
        self::assertSame(['application/json'], $request['headers']['Accept']);

        // Все cookies вычищены (любая может быть сессией/токеном).
        self::assertSame(self::REDACTED, $request['cookies']['PHPSESSID']);
        self::assertSame(self::REDACTED, $request['cookies']['remember_me']);

        // Тело: PII/секреты вычищены по ключу, безопасные поля сохранены.
        self::assertSame(self::REDACTED, $request['data']['user']['inn']);
        self::assertSame('Ivan', $request['data']['user']['name']);
        self::assertSame(self::REDACTED, $request['data']['access_token']);

        // Не-чувствительные метаданные не тронуты.
        self::assertSame('https://example.test/api', $request['url']);
        self::assertSame('POST', $request['method']);
    }

    public function testReturnsSameEventInstanceAndNeverDrops(): void
    {
        $event = Event::createEvent();

        $result = (new EventScrubber())($event);

        self::assertSame($event, $result);
    }
}
