<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Command;

use App\Marketplace\Command\OzonFinancialReportsSyncCommand;
use App\Marketplace\Infrastructure\Query\ActiveOzonConnectionsQuery;
use App\Marketplace\Message\SyncOzonAccrualByDayMessage;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class OzonFinancialReportsSyncCommandTest extends TestCase
{
    public function testDispatchesOneMessagePerConnectionPerDay(): void
    {
        $messages = [];
        $tester = new CommandTester($this->command(
            [['company_id' => 'c-1', 'id' => 'conn-1'], ['company_id' => 'c-2', 'id' => 'conn-2']],
            $messages,
        ));

        $tester->execute(['--days-back' => '3']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertCount(6, $messages);
        self::assertContainsOnlyInstancesOf(SyncOzonAccrualByDayMessage::class, $messages);

        $dates = array_values(array_unique(array_map(static fn (SyncOzonAccrualByDayMessage $m): string => $m->date, $messages)));
        self::assertCount(3, $dates, 'Три дня окна, каждый по одному разу на подключение.');
        self::assertSame(['2026-09-19', '2026-09-18', '2026-09-17'], $dates);
    }

    public function testRejectsOutOfRangeWindowInsteadOfFloodingTheQueue(): void
    {
        $messages = [];
        $tester = new CommandTester($this->command([['company_id' => 'c-1', 'id' => 'conn-1']], $messages));

        $tester->execute(['--days-back' => '0']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame([], $messages);
    }

    public function testNoActiveConnectionsIsSuccessNotFailure(): void
    {
        $messages = [];
        $tester = new CommandTester($this->command([], $messages));

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame([], $messages);
    }

    public function testCompanyFilterReachesTheConnectionsQuery(): void
    {
        $messages = [];
        $params = [];
        $tester = new CommandTester($this->command(
            [['company_id' => 'c-1', 'id' => 'conn-1']],
            $messages,
            $params,
        ));

        $tester->execute(['--days-back' => '1', '--company-id' => 'c-1']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertCount(1, $messages);
        self::assertArrayHasKey('company_id', $params, 'Фильтр обязан дойти до SQL, а не отсеиваться в PHP после выборки всех кабинетов.');
        self::assertSame('c-1', $params['company_id']);
    }

    public function testUnknownCompanyIsFailureNotSilentSuccess(): void
    {
        $messages = [];
        $tester = new CommandTester($this->command([], $messages));

        $tester->execute(['--company-id' => 'no-such-company']);

        // Молчаливый успех спрятал бы опечатку в UUID ровно там, где команду
        // запускают руками для перезалива.
        self::assertSame(1, $tester->getStatusCode());
        self::assertSame([], $messages);
    }

    public function testWindowReachingLegacyDaysIsRefusedInsteadOfDoubleCounting(): void
    {
        $messages = [];
        $tester = new CommandTester($this->command([['company_id' => 'c-1', 'id' => 'conn-1']], $messages));

        // От 20.09 окно в 20 дней достаёт до 31.08 — дня, уже покрытого снятым
        // форматом v3. Ключи у путей разные, поэтому повторная нормализация
        // добавила бы вторую продажу к существующей, а не обновила её.
        $tester->execute(['--days-back' => '20']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame([], $messages, 'Небезопасное окно не должно отправить ни одной задачи.');
        self::assertStringContainsString('2026-09-08', $tester->getDisplay());
    }

    public function testWindowEndingExactlyOnTheFirstSafeDayIsAllowed(): void
    {
        $messages = [];
        $tester = new CommandTester($this->command([['company_id' => 'c-1', 'id' => 'conn-1']], $messages));

        // 20.09 минус 12 дней = 08.09 — ровно граница, она допустима.
        $tester->execute(['--days-back' => '12']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertCount(12, $messages);
    }

    /**
     * @param list<array<string, mixed>> $connections
     * @param list<object> $messages
     * @param array<string, mixed> $capturedParams
     */
    private function command(array $connections, array &$messages, array &$capturedParams = []): OzonFinancialReportsSyncCommand
    {
        // ActiveOzonConnectionsQuery объявлен final: собираем настоящий поверх
        // мока DBAL, как в соседних тестах, вместо подмены класса.
        $dbal = $this->createMock(Connection::class);
        $dbal->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql, array $params = []) use ($connections, &$capturedParams): array {
                $capturedParams = $params;

                return $connections;
            },
        );
        $query = new ActiveOzonConnectionsQuery($dbal);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$messages): Envelope {
            $messages[] = $message;

            return new Envelope($message);
        });

        // Фиксированное «сегодня»: у команды есть нижняя граница по дате, и без
        // мока тест начал бы зависеть от того, в какой день его запустили.
        return new OzonFinancialReportsSyncCommand($query, $bus, new NullLogger(), new MockClock('2026-09-20 09:00:00', 'Europe/Moscow'));
    }
}
