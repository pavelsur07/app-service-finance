<?php

declare(strict_types=1);

namespace App\Tests\Functional\Logging;

use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;

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

    /**
     * Регрессия GlitchTip #416: отказ удалить статью баланса с потомками — ожидаемая ошибка
     * пользователя (422 со страницей), а не инцидент.
     */
    public function testBalanceValidationRefusalIsNotLoggedAsError(): void
    {
        $client = static::createClient();
        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $this->em()->persist($user);
        $this->em()->persist($company);
        $this->em()->flush();
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        // Первое открытие раздела создаёт стартовую структуру (ленивый сид).
        $client->request('GET', '/balance/structure/');
        self::assertResponseIsSuccessful();
        $groupId = (string) $this->em()->getConnection()->fetchOne(
            "SELECT id FROM balance_articles WHERE company_id = ? AND code = 'CURRENT_ASSETS'",
            [$company->getId()],
        );
        // Кнопка удаления живёт на странице редактирования статьи.
        $crawler = $client->request('GET', '/balance/structure/'.$groupId.'/edit');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[action="/balance/structure/'.$groupId.'/delete"]');
        self::assertCount(1, $form, 'Для группы со статьями должна быть форма удаления.');

        $client->submit($form->form());

        // Клиент перезагружает ядро перед каждым запросом, кроме первого: обработчик берём из
        // контейнера, обслужившего именно этот запрос, иначе он не увидит его записей.
        /** @var TestHandler $handler */
        $handler = static::getContainer()->get(TestHandler::class);

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Используйте архив', (string) $client->getResponse()->getContent());
        // hasErrorRecords() сверяет ровно уровень ERROR, а в проде было CRITICAL — смотрим всё от ERROR и выше.
        $incidents = array_filter(
            $handler->getRecords(),
            static fn (LogRecord $record): bool => $record->level->value >= Level::Error->value,
        );
        self::assertSame(
            [],
            array_map(static fn (LogRecord $record): string => $record->level->name.': '.$record->message, array_values($incidents)),
            'BalanceLedgerException (ожидаемый отказ) не должен логироваться на уровне ERROR+, иначе он уходит в GlitchTip.'
        );
        self::assertTrue($handler->hasWarningRecords(), 'Отказ должен остаться видимым в логах на уровне warning.');
    }
}
