<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Command;

use App\Tests\Support\Kernel\IntegrationTestCase;
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
        self::assertStringContainsString('Polled 0 orders', $tester->getDisplay());
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

    private function tester(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        $application = new Application($kernel);

        return new CommandTester($application->find('app:ingestion:orders:refresh-statuses'));
    }
}
