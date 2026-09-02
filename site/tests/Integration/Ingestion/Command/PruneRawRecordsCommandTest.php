<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Command;

use App\Tests\Support\Kernel\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PruneRawRecordsCommandTest extends IntegrationTestCase
{
    public function testDryRunReportsNothingToDelete(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('dry-run', $tester->getDisplay());
        self::assertStringContainsString('Pruned 0 raw record(s)', $tester->getDisplay());
    }

    /**
     * У необратимой команды умолчания нет: и «удалил, а не просили», и «не
     * удалил, а ждали» — одинаково плохие сюрпризы.
     *
     * @param array<string, bool|string> $options
     */
    #[DataProvider('invalidInvocationProvider')]
    public function testInvalidInvocationIsRejected(array $options): void
    {
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute($options));
    }

    /**
     * @return iterable<string, array{options: array<string, bool|string>}>
     */
    public static function invalidInvocationProvider(): iterable
    {
        yield 'no action chosen' => ['options' => []];
        yield 'both actions chosen' => ['options' => ['--dry-run' => true, '--execute' => true]];
        yield 'zero window' => ['options' => ['--dry-run' => true, '--older-than-days' => '0']];
        yield 'negative window' => ['options' => ['--dry-run' => true, '--older-than-days' => '-1']];
        yield 'non-numeric limit' => ['options' => ['--dry-run' => true, '--limit' => 'all']];
    }

    private function tester(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        return new CommandTester((new Application($kernel))->find('app:ingestion:raw:prune'));
    }
}
