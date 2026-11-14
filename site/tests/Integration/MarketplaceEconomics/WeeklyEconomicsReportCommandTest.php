<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceEconomics;

use App\Company\Facade\CompanyFacade;
use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceEconomics\Application\Service\SkuEconomicsReportBuilder;
use App\MarketplaceEconomics\Command\SendWeeklyEconomicsReportCommand;
use App\MarketplaceEconomics\Infrastructure\Export\SkuEconomicsXlsxExporter;
use App\MarketplaceEconomics\Infrastructure\Query\EconomicsStore;
use App\Notification\Contract\NotificationSenderInterface;
use App\Notification\DTO\EmailMessage;
use App\Notification\DTO\NotificationContext;
use App\Notification\Service\NotificationRouter;
use App\Telegram\Entity\ClientBinding;
use App\Telegram\Entity\TelegramBot;
use App\Telegram\Entity\TelegramUser;
use App\Telegram\Facade\TelegramFacade;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Twig\Environment;

final class WeeklyEconomicsReportCommandTest extends IntegrationTestCase
{
    private string $companyId;
    private string $otherId;
    /** @var list<EmailMessage> */
    private array $emails = [];
    /** @var list<string> */
    private array $telegramBodies = [];
    private bool $emailFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = Uuid::uuid7()->toString();
        $this->otherId = Uuid::uuid7()->toString();
        foreach ([$this->companyId, $this->otherId] as $i => $id) {
            $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail("weekly-$i-$id@example.test")->build();
            $company = CompanyBuilder::aCompany()->withId($id)->withOwner($owner)->build();
            $listing = MarketplaceListingBuilder::aListing()->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withMarketplaceSku("SKU-$i")->withName('Юбка')->build();
            $this->em->persist($owner);
            $this->em->persist($company);
            $this->em->persist($listing);
            $this->em->flush();
            // неделя 09.11–15.11 и прошлая 02.11–08.11
            $this->line($id, '2026-11-03', (string) $listing->getId(), 'sale', 'revenue', 100000, 1);
            $this->line($id, '2026-11-10', (string) $listing->getId(), 'sale', 'revenue', 300000, 3);
            $this->line($id, '2026-11-10', (string) $listing->getId(), 'ads_allocated', 'md2', -90000);
        }

        $bot = new TelegramBot(Uuid::uuid7()->toString(), 'test-token');
        $tgUser = new TelegramUser(Uuid::uuid7()->toString(), '424242');
        $company = $this->em->find(\App\Company\Entity\Company::class, $this->companyId);
        self::assertNotNull($company);
        $this->em->persist($bot);
        $this->em->persist($tgUser);
        $this->em->persist(new ClientBinding(Uuid::uuid7()->toString(), $company, $bot, $tgUser));
        $this->em->flush();
    }

    public function testSendsOnceToEmailAndTelegramOnlyForAllowedCompanies(): void
    {
        $tester = new CommandTester($this->command($this->companyId));

        self::assertSame(Command::SUCCESS, $tester->execute(['--week' => '2026-11-12']));
        self::assertStringContainsString('1 отправлено', $tester->getDisplay());
        self::assertCount(1, $this->emails, 'вторая компания не включена');
        self::assertStringContainsString('неделя 09.11–15.11.2026', (string) $this->emails[0]->subject);
        self::assertFileDoesNotExist($this->emails[0]->attachments[0]['path'], 'временный xlsx удалён');
        self::assertCount(1, $this->telegramBodies);
        self::assertStringContainsString('СМ2', $this->telegramBodies[0]);
        self::assertStringContainsString('ДРР выше 20', $this->telegramBodies[0], '900 из 3000 = 30 %');

        // повтор той же недели ничего не шлёт
        $tester->execute(['--week' => '2026-11-12']);
        self::assertSame([1, 1], $this->sentCounts());
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM me_weekly_report WHERE company_id = ?', [$this->companyId]));
    }

    public function testFailedEmailIsReleasedAndRetriedNextRun(): void
    {
        $this->emailFails = true;
        $tester = new CommandTester($this->command('*'));

        self::assertSame(Command::FAILURE, $tester->execute(['--week' => '2026-11-12']));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM me_weekly_report WHERE delivery = 'email'"));

        $this->emailFails = false;
        self::assertSame(Command::SUCCESS, $tester->execute(['--week' => '2026-11-12']));
        self::assertCount(2, $this->emails, 'обе компании при *');
        self::assertCount(1, $this->telegramBodies, 'Telegram только у компании с привязкой');
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM me_weekly_report WHERE company_id = ? AND delivery = 'telegram'", [$this->otherId]), 'без привязок отметка снята');
    }

    public function testDisabledWithoutAllowlistAndDryRunSendsNothing(): void
    {
        self::assertStringContainsString('выключено', $this->runWeekly('', []));
        self::assertStringContainsString('Выручка: 3', $this->runWeekly($this->companyId, ['--dry-run' => true]));
        self::assertSame([], $this->emails);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM me_weekly_report'));
    }

    /** @param array<string, mixed> $options */
    private function runWeekly(string $allowed, array $options): string
    {
        $tester = new CommandTester($this->command($allowed));
        $tester->execute(['--week' => '2026-11-12'] + $options);

        return $tester->getDisplay();
    }

    private function command(string $allowed): SendWeeklyEconomicsReportCommand
    {
        $c = self::getContainer();
        $sender = new class($this) implements NotificationSenderInterface {
            public function __construct(private readonly WeeklyEconomicsReportCommandTest $test)
            {
            }

            public function supports(): string
            {
                return 'email';
            }

            public function send(object $message, NotificationContext $ctx): bool
            {
                \assert($message instanceof EmailMessage);

                return $this->test->captureEmail($message);
            }
        };
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertStringEndsWith('/bottest-token/sendDocument', $url);
            // MockHttpClient отдаёт итерируемое тело замыканием
            $body = '';
            while ('' !== $chunk = (string) $options['body'](16384)) {
                $body .= $chunk;
            }
            $this->telegramBodies[] = $body;

            return new MockResponse('{"ok":true}');
        });
        $telegram = new TelegramFacade($c->get(\App\Telegram\Repository\TelegramBotRepository::class), $this->connection, $http, new NullLogger(), 'https://tg.test');

        return new SendWeeklyEconomicsReportCommand(
            $c->get(EconomicsStore::class), $c->get(SkuEconomicsReportBuilder::class), $c->get(SkuEconomicsXlsxExporter::class),
            $c->get(CompanyFacade::class), new NotificationRouter([$sender]), $telegram, $c->get(Environment::class),
            new MockClock('2026-11-16 09:00:00'), new NullLogger(), $allowed,
        );
    }

    /** @return array{int, int} писем и отправок Telegram */
    private function sentCounts(): array
    {
        return [\count($this->emails), \count($this->telegramBodies)];
    }

    public function captureEmail(EmailMessage $message): bool
    {
        if ($this->emailFails) {
            throw new \RuntimeException('SMTP down');
        }
        self::assertFileExists($message->attachments[0]['path']);
        $this->emails[] = $message;

        return true;
    }

    private function line(string $companyId, string $date, string $listingId, string $measure, string $level, int $amount, int $qty = 0): void
    {
        $this->connection->insert('me_day_line', [
            'id' => Uuid::uuid7()->toString(), 'company_id' => $companyId, 'channel' => 'ozon', 'connection_ref' => 'cabinet-1',
            'operation_date' => $date, 'listing_id' => $listingId, 'measure' => $measure, 'level' => $level, 'amount_minor' => $amount, 'quantity' => $qty,
        ]);
        $this->connection->executeStatement(
            "INSERT INTO me_day (company_id, channel, connection_ref, operation_date, rules_version, canon_minor, unallocated_minor, cost_price_minor, without_cost_price, line_count, currency, refreshed_at)
             VALUES (?, 'ozon', 'cabinet-1', ?, 'economics-2', 0, 0, 0, 0, 0, 'RUB', '2026-11-14 10:00:00') ON CONFLICT DO NOTHING",
            [$companyId, $date],
        );
    }
}
