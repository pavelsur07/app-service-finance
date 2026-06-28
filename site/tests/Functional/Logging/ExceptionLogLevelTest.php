<?php

declare(strict_types=1);

namespace App\Tests\Functional\Logging;

use App\Tests\Support\Kernel\WebTestCaseBase;
use Monolog\Handler\TestHandler;

/**
 * Регрессия Stage 1: клиентские 4xx-ошибки не должны логироваться на уровне ERROR.
 *
 * По умолчанию Symfony логирует 4xx HttpException на уровне ERROR, из-за чего они
 * попадают в отдельный sentry-handler (level: error) и засоряют GlitchTip.
 * framework.exceptions понижает уровень конкретных 4xx-подклассов до notice/warning.
 *
 * Тест красный на коде без правки framework.yaml и зелёный после неё.
 */
final class ExceptionLogLevelTest extends WebTestCaseBase
{
    public function testNotFoundIsNotLoggedAsError(): void
    {
        $client = static::createClient();

        /** @var TestHandler $handler */
        $handler = static::getContainer()->get(TestHandler::class);
        $handler->clear();

        $client->request('GET', '/__no_such_route_for_log_level_test__');

        self::assertSame(404, $client->getResponse()->getStatusCode());

        // 4xx — клиентская ошибка, не инцидент: записи уровня ERROR+ быть не должно,
        // иначе она уйдёт в sentry-handler и создаст ложный алерт в GlitchTip.
        self::assertFalse(
            $handler->hasErrorRecords(),
            'NotFoundHttpException (404) must not be logged at ERROR level; '
            .'otherwise it reaches the Sentry/GlitchTip handler as a false incident.'
        );

        // Контроль: исключение всё же залогировано (на пониженном уровне) —
        // проверяем именно поведение ErrorListener, а не пустой буфер.
        self::assertTrue(
            $handler->hasNoticeRecords(),
            'The 404 must still be logged (at notice level) to stay visible in app logs.'
        );
    }
}
