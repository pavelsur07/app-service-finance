<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Command;

use App\Shared\Command\DiskHealthCheckCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * df подменяется shell-скриптом с фиксированным выводом: числа 19.09.2026 —
 * inode 100%, байты 87%.
 */
final class DiskHealthCheckCommandTest extends TestCase
{
    private const HEADER_INODES = 'Filesystem Inodes Used Available Capacity Mounted on';
    private const HEADER_BYTES = 'Filesystem 1024-blocks Used Available Capacity Mounted on';

    /** @var list<string> */
    private array $scripts = [];

    protected function tearDown(): void
    {
        array_map('unlink', $this->scripts);
    }

    public function testBelowThresholdReturnsSuccessWithoutError(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $tester = $this->tester($logger, inodes: 'overlay 3276800 622592 2654208 19% /', bytes: 'overlay 40000000 17600000 22400000 44% /');

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('inodes 19%, bytes 44%', $tester->getDisplay());
    }

    public function testExhaustedInodesFailEvenWhenBytesAreFree(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('Disk usage above threshold', self::callback(
                static fn (array $context): bool => ['inodes', 'bytes'] === $context['exceeded']
                    && 100 === $context['inodes_used_percent']
                    && 87 === $context['bytes_used_percent']
                    && 85 === $context['threshold_percent'],
            ));

        $tester = $this->tester($logger, inodes: 'overlay 3276800 3273113 3687 100% /', bytes: 'overlay 40000000 34800000 5200000 87% /');

        self::assertSame(Command::FAILURE, $tester->execute([]));
    }

    public function testInodesAloneAboveThresholdFail(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('Disk usage above threshold', self::callback(
                static fn (array $context): bool => ['inodes'] === $context['exceeded'],
            ));

        $tester = $this->tester($logger, inodes: 'overlay 3276800 3273113 3687 100% /', bytes: 'overlay 40000000 17600000 22400000 44% /');

        self::assertSame(Command::FAILURE, $tester->execute([]));
    }

    /**
     * ext4 держит 5% блоков за root: used / total дал бы 81%, а `df -h` показывает 85%.
     * Гейт считает как df, иначе срабатывал бы только около 90%.
     */
    public function testReservedBlocksCountAsUnavailable(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('Disk usage above threshold', self::callback(
                static fn (array $context): bool => ['bytes'] === $context['exceeded'] && 85 === $context['bytes_used_percent'],
            ));

        $tester = $this->tester($logger, inodes: 'overlay 3276800 622592 2654208 19% /', bytes: 'overlay 40000000 32300000 5700000 85% /');

        self::assertSame(Command::FAILURE, $tester->execute([]));
    }

    public function testPercentIsRoundedUpToTheThreshold(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Disk usage above threshold', self::anything());

        // 84.01% → 85%: гейт не должен молчать на доле процента ниже порога.
        $tester = $this->tester($logger, inodes: 'overlay 10000 8401 1599 84% /', bytes: 'overlay 40000000 17600000 22400000 44% /');

        self::assertSame(Command::FAILURE, $tester->execute([]));
    }

    #[DataProvider('invalidThresholds')]
    public function testInvalidThresholdIsRejected(string $threshold): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $tester = $this->tester($logger, inodes: 'overlay 3276800 622592 2654208 19% /', bytes: 'overlay 40000000 17600000 22400000 44% /');

        self::assertSame(Command::INVALID, $tester->execute(['--threshold' => $threshold]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidThresholds(): iterable
    {
        yield 'not a number' => ['abc'];
        yield 'zero' => ['0'];
        yield 'above 100' => ['150'];
    }

    public function testFilesystemWithoutInodeLimitIsNotApplicable(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $tester = $this->tester($logger, inodes: 'btrfs 0 0 0 - /', bytes: 'btrfs 40000000 17600000 22400000 44% /');

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('inodes n/a', $tester->getDisplay());
    }

    public function testUnreadableDfOutputIsFailure(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Disk healthcheck FAILED', self::anything());

        $tester = $this->tester($logger, inodes: 'garbage', bytes: 'garbage');

        self::assertSame(Command::FAILURE, $tester->execute([]));
    }

    public function testFailingDfIsFailure(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Disk healthcheck FAILED', self::anything());

        $tester = new CommandTester(new DiskHealthCheckCommand($logger, $this->script('exit 1')));

        self::assertSame(Command::FAILURE, $tester->execute([]));
    }

    private function tester(LoggerInterface $logger, string $inodes, string $bytes): CommandTester
    {
        $body = sprintf(
            "case \"\$1\" in\n  -Pi) printf '%%s\\n%%s\\n' '%s' '%s' ;;\n  *) printf '%%s\\n%%s\\n' '%s' '%s' ;;\nesac",
            self::HEADER_INODES,
            $inodes,
            self::HEADER_BYTES,
            $bytes,
        );

        return new CommandTester(new DiskHealthCheckCommand($logger, $this->script($body)));
    }

    private function script(string $body): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'fake-df-');
        file_put_contents($path, "#!/bin/sh\n".$body."\n");
        chmod($path, 0755);
        $this->scripts[] = $path;

        return $path;
    }
}
