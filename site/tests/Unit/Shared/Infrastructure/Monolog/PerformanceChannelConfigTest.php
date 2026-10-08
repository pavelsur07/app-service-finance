<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Monolog;

use App\Shared\Infrastructure\Performance\PerformanceLogReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Канал диагностики M1 (`performance`): отдельный файл с ограниченным retention, который
 * читает app:marketplace:perf-report, и никуда больше — ни в буфер fingers_crossed `main`
 * (на ошибке он выплеснул бы до 50 событий в stderr), ни в консоль воркеров.
 */
final class PerformanceChannelConfigTest extends TestCase
{
    public function testProdWritesPerformanceToABoundedDailyFileThatTheReportReads(): void
    {
        $config = Yaml::parseFile(__DIR__.'/../../../../../config/packages/monolog.yaml');
        $handlers = $config['when@prod']['monolog']['handlers'];

        self::assertContains('performance', $config['monolog']['channels']);
        self::assertSame('rotating_file', $handlers['performance']['type']);
        self::assertSame(['performance'], $handlers['performance']['channels']);
        self::assertSame('info', $handlers['performance']['level']);
        self::assertSame(14, $handlers['performance']['max_files']);
        self::assertSame(0o666, $handlers['performance']['file_permission']);
        self::assertSame('%kernel.logs_dir%/performance.jsonl', $handlers['performance']['path']);
        // RotatingFileHandler: performance.jsonl → performance-<Y-m-d>.jsonl — то, что ищет читатель.
        $path = pathinfo((string) $handlers['performance']['path']);
        self::assertSame(
            PerformanceLogReader::FILE_PREFIX.'2026-10-08'.PerformanceLogReader::FILE_SUFFIX,
            $path['filename'].'-2026-10-08.'.($path['extension'] ?? ''),
        );

        self::assertContains('!performance', $handlers['main']['channels']);
        self::assertContains('!performance', $handlers['console']['channels']);
    }

    public function testDiagnosticsIsOffUnlessTheFlagIsSet(): void
    {
        $services = Yaml::parseFile(__DIR__.'/../../../../../config/services.yaml', Yaml::PARSE_CUSTOM_TAGS);

        self::assertSame('0', $services['parameters']['app.performance_diagnostics_default']);
        self::assertStringContainsString('MARKETPLACE_PERF_DIAGNOSTICS', $services['parameters']['app.performance_diagnostics_enabled']);
    }
}
