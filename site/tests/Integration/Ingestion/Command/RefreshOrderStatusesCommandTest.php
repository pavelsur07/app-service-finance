<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Command;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Ingestion\Enum\IngestOrderScheme;
use App\Ingestion\Enum\IngestOrderStatus;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Exception\MalformedConnectorResponseException;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Ingestion\IngestOrderBuilder;
use App\Tests\Integration\Ingestion\Fixtures\FakeOzonOrdersClient;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RefreshOrderStatusesCommandTest extends IntegrationTestCase
{
    public function testEmptyRunReportsZeroes(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Requested 0 orders', $tester->getDisplay());
    }

    /**
     * Ввод разбирается строго: «0», «abc» и отрицательное обязаны быть
     * ошибкой, а не молча превращаться в пустой прогон, который отчитается
     * успехом. Крон запускается с --quiet, и тихий успех никто не заметит.
     *
     * @param array<string, string> $options
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidOptionsProvider')]
    public function testInvalidOptionsAreRejected(array $options): void
    {
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute($options));
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function invalidOptionsProvider(): iterable
    {
        yield 'дни ноль' => [['--days' => '0']];
        yield 'дни не число' => [['--days' => 'месяц']];
        yield 'дни отрицательные' => [['--days' => '-1']];
        yield 'дни выше предела' => [['--days' => '400']];
        yield 'лимит ноль' => [['--limit' => '0']];
        yield 'лимит выше предела' => [['--limit' => '5000']];
        yield 'компания не uuid' => [['--company-id' => 'not-a-uuid']];
    }

    /**
     * Неустранимая несовместимость API обязана давать НЕНУЛЕВОЙ код возврата.
     *
     * Ответ, нарушающий контракт эндпоинта, через час будет ровно таким же.
     * Пока он считался наравне с 429, прогон заканчивался нулём и одним
     * `warning` — а крон запускается с `--quiet`, и подключение, которое не
     * обновляется вовсе, выглядело бы совершенно здоровым.
     */
    public function testEndpointWideBreakageMakesTheCommandFail(): void
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('refresh-exit-'.Uuid::uuid4()->toString().'@example.com');
        $user->setPassword('password');

        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('Refresh Exit Code Company');

        $connectionId = '77777777-7777-7777-7777-0000000ff0e1';
        $connection = new MarketplaceConnection(
            id: $connectionId,
            company: $company,
            marketplace: MarketplaceType::OZON,
            connectionType: MarketplaceConnectionType::SELLER,
        );
        $connection->setApiKey('test-key');
        $connection->setClientId('test-client-id');
        $connection->setIsActive(true);

        $order = IngestOrderBuilder::anOrder()
            ->forCompany((string) $company->getId())
            ->withConnectionRef($connectionId)
            ->withSource(IngestSource::OZON)
            ->withScheme(IngestOrderScheme::FBO)
            ->withExternalId('posting-1')
            ->withStatus(IngestOrderStatus::SHIPPED, 'delivering')
            ->orderedAt(new \DateTimeImmutable('-2 days'))
            ->build();

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($connection);
        $this->em->persist($order);
        $this->em->flush();

        /** @var FakeOzonOrdersClient $ozon */
        $ozon = self::getContainer()->get(FakeOzonOrdersClient::class);
        $ozon->setPostingFailures(['posting-1' => new MalformedConnectorResponseException(
            'Unexpected HTTP status from the postings endpoint.',
            endpointWide: true,
        )]);

        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));

        // Вывод переносится по ширине, поэтому пробелы схлопываются.
        self::assertStringContainsString(
            'broken connections: 1',
            (string) preg_replace('/\s+/', ' ', $tester->getDisplay()),
        );
    }

    private function tester(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        $application = new Application($kernel);

        return new CommandTester($application->find('app:ingestion:orders:refresh-statuses'));
    }
}
