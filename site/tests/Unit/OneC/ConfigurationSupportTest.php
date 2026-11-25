<?php

declare(strict_types=1);

namespace App\Tests\Unit\OneC;

use App\OneC\Application\ConfigurationSupport;
use App\OneC\Application\HandshakeSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class ConfigurationSupportTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, string}> */
    public static function versions(): iterable
    {
        yield 'новый релиз БП 3.0' => ['БухгалтерияПредприятия', '3.0.100.1', '1.2', 'supported'];
        yield 'старый релиз БП 3.0' => ['БухгалтерияПредприятия', '3.0.9.1', '1.2', 'warning'];
        yield 'устаревшая обработка' => ['БухгалтерияПредприятия', '3.0.100.1', '1.1', 'unsupported'];
        yield 'другая редакция' => ['БухгалтерияПредприятия', '3.01', '1.2', 'unsupported'];
        yield 'другая конфигурация' => ['УправлениеТорговлей', '3.0.100', '1.2', 'unsupported'];
    }

    public function testSeparatorsAndEscapedQuotesInAnExtensionStringDoNotCountAsStructure(): void
    {
        $baseId = Uuid::uuid7()->toString();
        $json = json_encode([
            'base_id' => $baseId, 'config_name' => 'БухгалтерияПредприятия', 'config_version' => '3.0',
            'platform_version' => '8.3', 'processor_version' => '1', 'organizations' => [],
            'extension' => str_repeat('"{[,,,\\', 20000),
        ], \JSON_THROW_ON_ERROR);
        self::assertSame($baseId, HandshakeSnapshot::parse($json)->baseId);
    }

    #[DataProvider('versions')]
    public function testSupport(string $name, string $version, string $processor, string $expected): void
    {
        $snapshot = HandshakeSnapshot::parse(json_encode([
            'base_id' => Uuid::uuid7()->toString(), 'config_name' => $name, 'config_version' => $version,
            'platform_version' => '8.3', 'processor_version' => $processor, 'organizations' => [], 'extension' => 1,
        ], \JSON_THROW_ON_ERROR));
        $policy = new ConfigurationSupport('БухгалтерияПредприятия', '3.0.10', '1.2');
        self::assertSame($expected, $policy->evaluate($snapshot)['level']);
    }
}
